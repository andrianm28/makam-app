<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Http\Middleware\RequireRecentAuthentication;
use App\Models\User;
use App\Platform\Audit\AuditOutcome;
use App\Platform\Audit\Models\AuditEvent;
use App\Platform\IdentityAccess\Models\ActorSession;
use App\Platform\IdentityAccess\Reauthentication\Models\ReauthenticationEvent;
use App\Platform\IdentityAccess\Roles\ActorRole;
use App\Platform\IdentityAccess\Roles\Models\ActorRoleAssignment;
use App\Platform\Payment\Models\PaymentReversal;
use App\Platform\Payment\Models\PaymentSession;
use App\Platform\Payment\PaymentAuditActions;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The HTTP half of Task 6's safe slice — `RequireRecentAuthentication`'s
 * THIRD real attachment anywhere in this repo, following
 * `VerifyManualPaymentRouteTest`'s exact precedent (route name/shape
 * changes only). `ReversalServiceTest` covers the action's own
 * write/audit/structural behaviour; this file proves the route is
 * genuinely gated by the middleware, not merely that
 * `ReversalService::record()` works when called directly.
 *
 * ---------------------------------------------------------------------------
 * Why every success-path test now grants a role
 * ---------------------------------------------------------------------------
 * These tests used to authenticate with a bare `actingAs($user)` and nothing
 * else, which was sufficient because the route's only gates were `auth` and
 * `RequireRecentAuthentication`. That was the vulnerability: `config/auth.php`
 * defines a single `web` guard over the shared `users` table, so any
 * authenticated user could record a refund or a chargeback.
 * `PaymentActionAuthorizer` now requires a real `finance` or
 * `restricted_admin` grant, so a bare user is refused 403 and every
 * success-path test has to establish one. That these tests failed before
 * being updated is the first evidence the fix bites.
 */
final class RecordPaymentReversalRouteTest extends TestCase
{
    use RefreshDatabase;

    private function url(string $reversalType): string
    {
        return route('admin.payments.reversals.record', ['reversalType' => $reversalType]);
    }

    /**
     * A user who satisfies BOTH gates: a recent `actor_sessions` row for
     * `RequireRecentAuthentication`, and an authorized role grant for
     * `PaymentActionAuthorizer`.
     */
    private function authorizedUser(string $role = ActorRole::FINANCE): User
    {
        $user = $this->freshlyAuthenticatedUser();

        $this->grantRole($user, $role);

        return $user;
    }

    /**
     * A user who satisfies the re-authentication gate only — no role grant.
     * The shape every denial test needs: without the `actor_sessions` row
     * the middleware would redirect to the challenge first and the
     * authorization refusal would never be reached, so a test built on a
     * bare user would pass for the wrong reason.
     */
    private function freshlyAuthenticatedUser(): User
    {
        $user = User::factory()->create();

        ActorSession::query()->create([
            'user_id' => $user->id,
            'session_id' => 'test-session-'.$user->id,
            'guard' => 'web',
            'last_authenticated_at' => CarbonImmutable::now()->subSeconds(10),
        ]);

        return $user;
    }

    /**
     * Grants a role the way production does: a real, non-revoked
     * `actor_role_assignments` row. `LocalUsersTableIdentityAccessAdapter`
     * reads that table through `Roles\ActorRoleReader` to populate
     * `ActorContext::$roles`, so this exercises the whole live chain —
     * table → reader → adapter → resolver → authorizer — rather than
     * binding a hand-built `ActorContext` into the container the way the
     * direct-call Action tests (`ManualPayoutTest`) do. For an HTTP test of
     * an authorization fix, stubbing out the identity resolution would
     * remove the part most worth proving.
     */
    private function grantRole(User $user, string $role): ActorRoleAssignment
    {
        return ActorRoleAssignment::create([
            'actor_identifier' => (string) $user->id,
            'role' => $role,
        ]);
    }

    /**
     * The trace every refusal must leave, asserted in one place so the two
     * halves cannot drift: exactly ONE `Denied` audit row naming this
     * endpoint, no `Allowed` row anywhere, and none of the downstream writes.
     *
     * This replaces the older `AuditEvent::query()->count() === 0` shape,
     * which froze "a refused money-moving action leaves no trace" into the
     * test suite (review finding SF-6). Three properties keep it from going
     * vacuous:
     *
     * - `sole()` fails on ZERO rows and on TWO, so both deleting the audit
     *   write and double-writing it are caught. A `>= 1` assertion would
     *   catch neither.
     * - the `Allowed` count is asserted separately, so the refusal path
     *   cannot start minting `satisfied`/`Allowed` rows and still pass by
     *   virtue of having produced a `Denied` one too.
     * - the row's own fields are pinned, so an audit write that recorded the
     *   wrong action, the wrong subject, or the caller's payload fails here
     *   rather than silently counting as "audited".
     */
    private function assertTheRefusalWasAuditedExactlyOnce(): void
    {
        $denial = AuditEvent::query()
            ->where('action', PaymentAuditActions::ADMIN_ACTION_DENIED)
            ->sole();

        $this->assertSame(AuditOutcome::Denied->value, $denial->outcome);
        $this->assertSame('payment_admin_action', $denial->subject_type);
        $this->assertSame('payment_reversal', $denial->subject_id);

        // A fixed server-side reason, never the caller's text — authorization
        // runs before validation, so the request body is unvalidated here.
        $this->assertNotNull($denial->reason);
        $this->assertStringNotContainsString('TRX-', (string) $denial->reason);
        $this->assertSame([], $denial->metadata);

        $this->assertSame(
            0,
            AuditEvent::query()->where('outcome', AuditOutcome::Allowed->value)->count(),
            'A refused actor must not collect a single Allowed audit row.',
        );
        $this->assertSame(0, PaymentReversal::query()->count());
        $this->assertSame(0, ReauthenticationEvent::query()->count());
    }

    public function test_recording_without_a_fresh_authentication_redirects_to_the_challenge_and_changes_nothing(): void
    {
        $user = User::factory()->create();
        $this->grantRole($user, ActorRole::FINANCE);

        // Deliberately NOT creating an actor_sessions row with a recent
        // last_authenticated_at — RequireRecentAuthentication fails closed
        // on a null timestamp, same as DisableMfaControllerTest's and
        // VerifyManualPaymentRouteTest's own precedent. The role IS granted
        // so this still pins the middleware specifically: the redirect
        // cannot be the authorization refusal wearing the middleware's
        // clothes.
        $this->actingAs($user)
            ->post($this->url('refund'), [
                'reference' => 'TRX-route-1',
                'reason' => 'Customer requested a refund',
            ])
            ->assertRedirect(route('filament.admin.pages.mfa-challenge'));

        $this->assertSame(0, PaymentReversal::query()->count());
        $this->assertSame(0, AuditEvent::query()->where('action', PaymentAuditActions::REFUND)->count());
    }

    public function test_recording_a_refund_with_a_fresh_authentication_actually_records_it(): void
    {
        $user = $this->authorizedUser();

        $this->actingAs($user)
            ->post($this->url('refund'), [
                'reference' => 'TRX-route-2',
                'amount_minor' => 10_000_00,
                'reason' => 'Customer requested a refund',
            ])
            ->assertRedirect(route('filament.admin.pages.dashboard'));

        $reversal = PaymentReversal::query()->where('reference', 'TRX-route-2')->first();
        $this->assertNotNull($reversal);
        $this->assertSame(10_000_00, $reversal->amount_minor);
        $this->assertSame(1, AuditEvent::query()->where('action', PaymentAuditActions::REFUND)->count());
    }

    public function test_recording_a_chargeback_with_a_fresh_authentication_actually_records_it(): void
    {
        $user = $this->authorizedUser();

        $this->actingAs($user)
            ->post($this->url('chargeback'), [
                'reference' => 'TRX-route-3',
                'reason' => 'Card issuer disputed the transaction',
            ])
            ->assertRedirect(route('filament.admin.pages.dashboard'));

        $reversal = PaymentReversal::query()->where('reference', 'TRX-route-3')->first();
        $this->assertNotNull($reversal);
        $this->assertSame(1, AuditEvent::query()->where('action', PaymentAuditActions::CHARGEBACK)->count());
    }

    // -----------------------------------------------------------------
    // Authorization (the hotfix)
    // -----------------------------------------------------------------

    /**
     * The vulnerability itself, inverted into a test. Before the fix this
     * exact request recorded a real refund.
     *
     * The `ReauthenticationEvent` assertion is the one that pins ORDERING,
     * not merely the refusal: `ReauthenticationService::satisfy()` verifies
     * nothing — it unconditionally writes a `satisfied` event, an `Allowed`
     * audit row, and clears the MFA rate limiter. The controller used to
     * call it as its first statement, so a refused actor would still have
     * collected a "re-proved their identity" trail and a reset limiter.
     * Authorizing first is what keeps this table empty.
     */
    public function test_an_authenticated_actor_with_no_role_is_refused_and_writes_nothing(): void
    {
        $user = $this->freshlyAuthenticatedUser();

        $this->actingAs($user)
            ->post($this->url('refund'), [
                'reference' => 'TRX-route-denied',
                'reason' => 'Customer requested a refund',
            ])
            ->assertForbidden();

        $this->assertSame(0, AuditEvent::query()->where('action', PaymentAuditActions::REFUND)->count());
        $this->assertTheRefusalWasAuditedExactlyOnce();
    }

    public function test_a_real_but_unauthorized_role_is_refused(): void
    {
        // `customer` is a genuine entry in ActorRole::KNOWN_ROLES — this
        // proves the check is an allow-list of two roles, not merely
        // "holds any role at all."
        $user = $this->authorizedUser(ActorRole::CUSTOMER);

        $this->actingAs($user)
            ->post($this->url('refund'), [
                'reference' => 'TRX-route-customer',
                'reason' => 'Customer requested a refund',
            ])
            ->assertForbidden();

        $this->assertTheRefusalWasAuditedExactlyOnce();
    }

    public function test_a_revoked_role_grant_no_longer_authorizes(): void
    {
        $user = $this->freshlyAuthenticatedUser();
        $this->grantRole($user, ActorRole::FINANCE)->revoke();

        $this->actingAs($user)
            ->post($this->url('refund'), [
                'reference' => 'TRX-route-revoked',
                'reason' => 'Customer requested a refund',
            ])
            ->assertForbidden();

        $this->assertTheRefusalWasAuditedExactlyOnce();
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function authorizedRoles(): iterable
    {
        yield 'finance' => [ActorRole::FINANCE];
        yield 'restricted_admin' => [ActorRole::RESTRICTED_ADMIN];
    }

    #[DataProvider('authorizedRoles')]
    public function test_each_authorized_role_may_record_a_reversal(string $role): void
    {
        $user = $this->authorizedUser($role);

        $this->actingAs($user)
            ->post($this->url('refund'), [
                'reference' => 'TRX-route-'.$role,
                'reason' => 'Customer requested a refund',
            ])
            ->assertRedirect(route('filament.admin.pages.dashboard'));

        $this->assertSame(1, PaymentReversal::query()->where('reference', 'TRX-route-'.$role)->count());
    }

    /**
     * Both call sites used to hardcode `actorRole: 'authenticated_actor'`.
     * Per `Roles\ActorRole`'s own doc block that value is an audit sentinel
     * meaning "no role applies" and must never be grantable — so the audit
     * trail for every reversal recorded the ABSENCE of a role. Both the
     * audit row and the re-authentication event must now carry the role the
     * authorizer actually approved.
     */
    #[DataProvider('authorizedRoles')]
    public function test_the_audit_trail_records_the_real_role_never_the_sentinel(string $role): void
    {
        $user = $this->authorizedUser($role);

        $this->actingAs($user)
            ->post($this->url('refund'), [
                'reference' => 'TRX-route-audit-'.$role,
                'reason' => 'Customer requested a refund',
            ])
            ->assertRedirect(route('filament.admin.pages.dashboard'));

        $audit = AuditEvent::query()->where('action', PaymentAuditActions::REFUND)->sole();
        $this->assertSame($role, $audit->actor_role);

        $reauthentication = ReauthenticationEvent::query()->sole();
        $this->assertSame($role, $reauthentication->actor_role);

        $this->assertSame(0, AuditEvent::query()->where('actor_role', 'authenticated_actor')->count());
        $this->assertSame(0, ReauthenticationEvent::query()->where('actor_role', 'authenticated_actor')->count());
    }

    /**
     * An actor holding both roles is recorded under the more privileged one
     * — `ActorRole::KNOWN_ROLES` declaration order is precedence order, and
     * the authorizer walks it in that order rather than returning whichever
     * grant the database happened to yield first.
     */
    public function test_an_actor_holding_both_roles_is_recorded_under_the_more_privileged_one(): void
    {
        $user = $this->freshlyAuthenticatedUser();
        $this->grantRole($user, ActorRole::FINANCE);
        $this->grantRole($user, ActorRole::RESTRICTED_ADMIN);

        $this->actingAs($user)
            ->post($this->url('refund'), [
                'reference' => 'TRX-route-both',
                'reason' => 'Customer requested a refund',
            ])
            ->assertRedirect(route('filament.admin.pages.dashboard'));

        $audit = AuditEvent::query()->where('action', PaymentAuditActions::REFUND)->sole();
        $this->assertSame(ActorRole::RESTRICTED_ADMIN, $audit->actor_role);
    }

    /**
     * Authorization runs BEFORE `$request->validate()`, so a refused actor
     * gets a flat 403 rather than a 422 telling them whether their payload
     * was well-formed.
     */
    public function test_an_unauthorized_actor_gets_403_not_a_validation_error(): void
    {
        $user = $this->freshlyAuthenticatedUser();

        $this->actingAs($user)
            ->post($this->url('refund'), [
                // Missing `reference` and `reason` entirely — a well-formed
                // request would be a 422 here.
            ])
            ->assertForbidden();

        // And the refusal is still audited even though nothing about the
        // request was ever validated — the refusal row carries no part of it.
        $this->assertTheRefusalWasAuditedExactlyOnce();
    }

    /**
     * The refusal-audit write is best-effort: it runs before `abort(403)`, so
     * the row is never lost to a later failure, but a failure of the write
     * itself must not change the answer the caller gets.
     *
     * Two reasons this matters enough to pin, both in
     * `RecordPaymentActionRefusal`'s doc block: a refusal mutates nothing, so
     * there is no half-done state for an audit failure to protect; and a
     * propagating failure would turn every refusal into a 500 during an audit
     * outage, which is both a worse answer and a change in the flat-403
     * property the existence-oracle defence depends on. A monitoring
     * improvement must not become a way to move the authorization surface.
     *
     * The table is dropped rather than mocked because `Audit` is a static
     * write API with no seam — and dropping it is a faithful stand-in for the
     * real failure mode (the audit write raising a `QueryException`).
     *
     * This test deliberately asserts the response and nothing else. On
     * PostgreSQL the failed audit INSERT aborts the surrounding transaction
     * (SQLSTATE 25P02, "current transaction is aborted"), so every later query
     * in this test would fail for that reason rather than reveal anything
     * about the refusal — the assertions would be reporting the poisoned
     * transaction, not the behaviour under test. SQLite does not poison the
     * transaction, which is why an earlier version of this test passed there
     * and failed only on a real PostgreSQL run.
     *
     * That costs nothing: the "a refusal writes no domain row and no
     * reauthentication event" property is pinned, on a healthy audit table, by
     * `test_an_authenticated_actor_with_no_role_is_refused_and_writes_nothing`.
     * The only thing this test can pin that no other test does is that an
     * audit outage still yields 403 rather than 500, and that is what it does.
     */
    public function test_a_failed_refusal_audit_still_returns_403_and_never_500(): void
    {
        $user = $this->freshlyAuthenticatedUser();

        Schema::drop('audit_events');

        $this->actingAs($user)
            ->post($this->url('refund'), [
                'reference' => 'TRX-route-audit-down',
                'reason' => 'Customer requested a refund',
            ])
            ->assertForbidden();
    }

    // -----------------------------------------------------------------
    // Input validation (unchanged behaviour, now behind the role gate)
    // -----------------------------------------------------------------

    public function test_a_missing_reason_is_rejected_at_the_http_layer(): void
    {
        $user = $this->authorizedUser();

        $this->actingAs($user)
            ->post($this->url('refund'), [
                'reference' => 'TRX-route-4',
                'reason' => '',
            ])
            ->assertSessionHasErrors('reason');

        $this->assertSame(0, PaymentReversal::query()->where('reference', 'TRX-route-4')->count());
    }

    /**
     * `Audit::record()` treats the whole of `\p{C}` as blank, but Laravel's
     * `TrimStrings` middleware only strips the subset it considers
     * invisible whitespace (`Str::INVISIBLE_CHARACTERS` plus Unicode
     * `\s`). A non-breaking space is in that subset, so it never reaches
     * the controller as a non-empty value — but a control character
     * (U+0001) or a private-use character (U+E000) is not, and before
     * `NonBlankReason` was attached those passed `required`, reached
     * `Audit::record()`, and raised `AuditReasonRequiredException` — a
     * plain `RuntimeException` with no `render()` — so the operator got a
     * 500 and lost their input, for what is a validation failure. The
     * audit outcome was already correct (`Audit::wrap()` rolls back);
     * only the reporting was wrong.
     */
    #[DataProvider('blankReasonsThatSurviveTrimStrings')]
    public function test_a_reason_the_audit_layer_calls_blank_is_rejected_as_validation_not_as_a_server_error(string $reason): void
    {
        $user = $this->authorizedUser();

        $response = $this->actingAs($user)
            ->post($this->url('refund'), [
                'reference' => 'TRX-route-blank',
                'reason' => $reason,
            ]);

        $this->assertNotSame(500, $response->getStatusCode());
        $response->assertSessionHasErrors('reason');

        $this->assertSame(0, PaymentReversal::query()->where('reference', 'TRX-route-blank')->count());
        $this->assertSame(0, AuditEvent::query()->where('action', PaymentAuditActions::REFUND)->count());
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function blankReasonsThatSurviveTrimStrings(): iterable
    {
        // Not stripped by TrimStrings; these are the cases that actually
        // reached Audit::record() and produced a 500.
        yield 'control character U+0001' => ["\u{0001}"];
        yield 'private-use character U+E000' => ["\u{E000}"];

        // Stripped by TrimStrings before validation runs, so this one is
        // already rejected by `required` alone. Kept to pin that boundary:
        // if TrimStrings ever stops covering it, this stays green via
        // NonBlankReason rather than silently becoming a 500.
        yield 'non-breaking space U+00A0' => ["\u{00A0}"];
    }

    public function test_a_missing_reference_is_rejected_at_the_http_layer(): void
    {
        $user = $this->authorizedUser();

        $this->actingAs($user)
            ->post($this->url('refund'), [
                'reason' => 'Some reason',
            ])
            ->assertSessionHasErrors('reference');
    }

    public function test_an_invalid_reversal_type_segment_is_rejected_by_the_route_itself(): void
    {
        // No role granted, and none needed: the route's own
        // `->whereIn('reversalType', ['refund', 'chargeback'])` constraint
        // means this URL matches no route at all, so the request never
        // reaches the controller's authorization check. Pinning it with an
        // unauthorized actor also pins that the 404 is genuinely the
        // router's, not a leak from inside the controller.
        $user = $this->freshlyAuthenticatedUser();

        $this->actingAs($user)
            ->post('/admin/payments/reversals/reversal', [
                'reference' => 'TRX-route-5',
                'reason' => 'Some reason',
            ])
            ->assertNotFound();

        // Never reached the controller, so there is no refusal to audit
        // either — the router's 404 must not mint a payment audit row.
        $this->assertSame(0, AuditEvent::query()->where('action', PaymentAuditActions::ADMIN_ACTION_DENIED)->count());
    }

    public function test_a_second_refund_for_the_same_reference_via_http_fails_and_leaves_one_row(): void
    {
        $user = $this->authorizedUser();

        $this->actingAs($user)->post($this->url('refund'), [
            'reference' => 'TRX-route-duplicate',
            'reason' => 'First refund',
        ])->assertRedirect(route('filament.admin.pages.dashboard'));

        $response = $this->actingAs($user)->post($this->url('refund'), [
            'reference' => 'TRX-route-duplicate',
            'reason' => 'Second refund attempt, must not be allowed',
        ]);

        $response->assertStatus(500);
        $this->assertSame(1, PaymentReversal::query()->where('reference', 'TRX-route-duplicate')->count());
    }

    public function test_an_unauthenticated_request_is_refused_by_the_auth_guard(): void
    {
        // Same gap `VerifyManualPaymentRouteTest`'s own precedent
        // documents — no `login` named route exists anywhere in this repo
        // yet, so Laravel's default `Authenticate` middleware's
        // `redirectTo()` throws `RouteNotFoundException` rather than
        // silently letting the request through. Asserted as "definitely
        // not a 2xx success" rather than a specific status, since the
        // exact rendering of a missing named route is a
        // framework/environment detail this test does not own.
        $response = $this->post($this->url('refund'), [
            'reference' => 'TRX-route-6',
            'reason' => 'Some reason',
        ]);

        $response->assertStatus(500);
        $this->assertSame(0, PaymentReversal::query()->where('reference', 'TRX-route-6')->count());
    }

    public function test_the_route_is_registered_with_the_reauthentication_middleware(): void
    {
        $route = collect(app('router')->getRoutes())
            ->first(fn ($route) => $route->getName() === 'admin.payments.reversals.record');

        $this->assertNotNull($route);
        $this->assertTrue(
            collect($route->gatherMiddleware())->contains(
                fn (string $middleware): bool => str_starts_with($middleware, RequireRecentAuthentication::class.':')
            ),
            'admin.payments.reversals.record must be gated by RequireRecentAuthentication.'
        );
    }

    public function test_recording_via_http_leaves_payment_sessions_untouched(): void
    {
        $user = $this->authorizedUser();

        $this->actingAs($user)->post($this->url('refund'), [
            'reference' => 'TRX-route-7',
            'reason' => 'Some reason',
        ]);

        $this->assertSame(0, PaymentSession::query()->count());
    }
}
