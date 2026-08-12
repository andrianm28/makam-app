<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Http\Middleware\RequireRecentAuthentication;
use App\Models\User;
use App\Platform\Audit\Models\AuditEvent;
use App\Platform\IdentityAccess\Models\ActorSession;
use App\Platform\IdentityAccess\Reauthentication\Models\ReauthenticationEvent;
use App\Platform\IdentityAccess\Roles\ActorRole;
use App\Platform\IdentityAccess\Roles\Models\ActorRoleAssignment;
use App\Platform\Payment\Models\PaymentSession;
use App\Platform\Payment\Models\PaymentVerification;
use App\Platform\Payment\PaymentAuditActions;
use App\Platform\Payment\PaymentVerificationStatus;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The HTTP half of Task 5's AC9 — `RequireRecentAuthentication`'s SECOND
 * real attachment anywhere in this repo, following
 * `DisableMfaControllerTest`'s exact precedent (route name changes only).
 * `VerifyManualPaymentTest` covers the action's own decision/audit/
 * structural behaviour; this file proves the route is genuinely gated by
 * the middleware, not merely that the underlying action works when called
 * directly.
 *
 * ---------------------------------------------------------------------------
 * Why every success-path test now grants a role
 * ---------------------------------------------------------------------------
 * These tests used to authenticate with a bare `actingAs($user)` and nothing
 * else, which was sufficient because the route's only gates were `auth` and
 * `RequireRecentAuthentication`. That was the vulnerability: `config/auth.php`
 * defines a single `web` guard over the shared `users` table, so any
 * authenticated user could approve or reject a manual payment.
 * `PaymentActionAuthorizer` now requires a real `finance` or
 * `restricted_admin` grant, so a bare user is refused 403 and every
 * success-path test has to establish one. That these tests failed before
 * being updated is the first evidence the fix bites.
 */
final class VerifyManualPaymentRouteTest extends TestCase
{
    use RefreshDatabase;

    private function submittedVerification(): PaymentVerification
    {
        return PaymentVerification::createSubmitted([
            'reference' => 'order-route-1',
            'payment_method' => 'bank_transfer',
            'payment_reference' => 'TRX-route-1',
            'instructions' => null,
            'submitted_at' => CarbonImmutable::now(),
        ]);
    }

    private function verifyUrl(PaymentVerification $verification): string
    {
        return route('admin.payments.manual-verifications.verify', $verification);
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

    public function test_verification_without_a_fresh_authentication_redirects_to_the_challenge_and_changes_nothing(): void
    {
        $user = User::factory()->create();
        $this->grantRole($user, ActorRole::FINANCE);
        $verification = $this->submittedVerification();

        // Deliberately NOT creating an actor_sessions row with a recent
        // last_authenticated_at — RequireRecentAuthentication fails closed
        // on a null timestamp, same as DisableMfaControllerTest's own
        // precedent. The role IS granted so this still pins the middleware
        // specifically: the redirect cannot be the authorization refusal
        // wearing the middleware's clothes.
        $this->actingAs($user)
            ->post($this->verifyUrl($verification), [
                'decision' => 'approve',
                'reason' => 'Proof matched provider statement',
            ])
            ->assertRedirect(route('filament.admin.pages.mfa-challenge'));

        $verification->refresh();
        $this->assertSame(PaymentVerificationStatus::Submitted, $verification->status());
        $this->assertSame(0, AuditEvent::query()->where('action', PaymentAuditActions::MANUAL_VERIFICATION)->count());
    }

    public function test_verification_with_a_fresh_authentication_actually_verifies(): void
    {
        $user = $this->authorizedUser();
        $verification = $this->submittedVerification();

        $this->actingAs($user)
            ->post($this->verifyUrl($verification), [
                'decision' => 'approve',
                'reason' => 'Proof matched provider statement',
            ])
            ->assertRedirect(route('filament.admin.pages.dashboard'));

        $verification->refresh();
        $this->assertSame(PaymentVerificationStatus::Verified, $verification->status());
        $this->assertSame(1, AuditEvent::query()->where('action', PaymentAuditActions::MANUAL_VERIFICATION)->count());
    }

    // -----------------------------------------------------------------
    // Authorization (the hotfix)
    // -----------------------------------------------------------------

    /**
     * The vulnerability itself, inverted into a test. Before the fix this
     * exact request approved a real payment.
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
        $verification = $this->submittedVerification();

        $this->actingAs($user)
            ->post($this->verifyUrl($verification), [
                'decision' => 'approve',
                'reason' => 'Proof matched provider statement',
            ])
            ->assertForbidden();

        $this->assertSame(PaymentVerificationStatus::Submitted, $verification->fresh()->status());
        $this->assertSame(0, AuditEvent::query()->where('action', PaymentAuditActions::MANUAL_VERIFICATION)->count());
        $this->assertSame(0, ReauthenticationEvent::query()->count());
    }

    public function test_a_real_but_unauthorized_role_is_refused(): void
    {
        // `customer` is a genuine entry in ActorRole::KNOWN_ROLES — this
        // proves the check is an allow-list of two roles, not merely
        // "holds any role at all."
        $user = $this->authorizedUser(ActorRole::CUSTOMER);
        $verification = $this->submittedVerification();

        $this->actingAs($user)
            ->post($this->verifyUrl($verification), [
                'decision' => 'approve',
                'reason' => 'Proof matched provider statement',
            ])
            ->assertForbidden();

        $this->assertSame(PaymentVerificationStatus::Submitted, $verification->fresh()->status());
        $this->assertSame(0, ReauthenticationEvent::query()->count());
    }

    public function test_a_revoked_role_grant_no_longer_authorizes(): void
    {
        $user = $this->freshlyAuthenticatedUser();
        $this->grantRole($user, ActorRole::FINANCE)->revoke();
        $verification = $this->submittedVerification();

        $this->actingAs($user)
            ->post($this->verifyUrl($verification), [
                'decision' => 'approve',
                'reason' => 'Proof matched provider statement',
            ])
            ->assertForbidden();

        $this->assertSame(PaymentVerificationStatus::Submitted, $verification->fresh()->status());
    }

    /**
     * The existence-oracle defence. The authorization check is
     * record-independent, so it runs BEFORE
     * `PaymentVerification::findOrFail()`. Had it run after, this endpoint
     * would answer 403 for a real verification id and 404 for a fabricated
     * one — handing anyone with a session a way to enumerate which
     * verification ids exist. An unauthorized actor must be unable to tell
     * the two apart.
     */
    public function test_an_unknown_verification_id_is_403_not_404_for_an_unauthorized_actor(): void
    {
        $user = $this->freshlyAuthenticatedUser();
        $real = $this->submittedVerification();

        $unknownUrl = route(
            'admin.payments.manual-verifications.verify',
            ['paymentVerification' => (string) Str::uuid()],
        );

        $unknown = $this->actingAs($user)->post($unknownUrl, [
            'decision' => 'approve',
            'reason' => 'Proof matched provider statement',
        ]);

        $existing = $this->actingAs($user)->post($this->verifyUrl($real), [
            'decision' => 'approve',
            'reason' => 'Proof matched provider statement',
        ]);

        $unknown->assertForbidden();
        $existing->assertForbidden();
        $this->assertSame(
            $existing->getStatusCode(),
            $unknown->getStatusCode(),
            'A real and a fabricated verification id must be indistinguishable to an unauthorized actor.',
        );
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
    public function test_each_authorized_role_may_decide_a_verification(string $role): void
    {
        $user = $this->authorizedUser($role);
        $verification = $this->submittedVerification();

        $this->actingAs($user)
            ->post($this->verifyUrl($verification), [
                'decision' => 'approve',
                'reason' => 'Proof matched provider statement',
            ])
            ->assertRedirect(route('filament.admin.pages.dashboard'));

        $this->assertSame(PaymentVerificationStatus::Verified, $verification->fresh()->status());
    }

    /**
     * Both call sites used to hardcode `actorRole: 'authenticated_actor'`.
     * Per `Roles\ActorRole`'s own doc block that value is an audit sentinel
     * meaning "no role applies" and must never be grantable — so the audit
     * trail for every manual verification recorded the ABSENCE of a role.
     * Both the audit row and the re-authentication event must now carry the
     * role the authorizer actually approved.
     */
    #[DataProvider('authorizedRoles')]
    public function test_the_audit_trail_records_the_real_role_never_the_sentinel(string $role): void
    {
        $user = $this->authorizedUser($role);
        $verification = $this->submittedVerification();

        $this->actingAs($user)
            ->post($this->verifyUrl($verification), [
                'decision' => 'approve',
                'reason' => 'Proof matched provider statement',
            ])
            ->assertRedirect(route('filament.admin.pages.dashboard'));

        $audit = AuditEvent::query()->where('action', PaymentAuditActions::MANUAL_VERIFICATION)->sole();
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
        $verification = $this->submittedVerification();

        $this->actingAs($user)
            ->post($this->verifyUrl($verification), [
                'decision' => 'approve',
                'reason' => 'Proof matched provider statement',
            ])
            ->assertRedirect(route('filament.admin.pages.dashboard'));

        $audit = AuditEvent::query()->where('action', PaymentAuditActions::MANUAL_VERIFICATION)->sole();
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
        $verification = $this->submittedVerification();

        $this->actingAs($user)
            ->post($this->verifyUrl($verification), [
                // Missing `decision` and `reason` entirely — a well-formed
                // request would be a 422 here.
            ])
            ->assertForbidden();
    }

    // -----------------------------------------------------------------
    // Input validation (unchanged behaviour, now behind the role gate)
    // -----------------------------------------------------------------

    public function test_a_missing_reason_is_rejected_at_the_http_layer(): void
    {
        $user = $this->authorizedUser();
        $verification = $this->submittedVerification();

        $this->actingAs($user)
            ->post($this->verifyUrl($verification), [
                'decision' => 'approve',
                'reason' => '',
            ])
            ->assertSessionHasErrors('reason');

        $verification->refresh();
        $this->assertSame(PaymentVerificationStatus::Submitted, $verification->status());
    }

    /**
     * See the sibling test in `RecordPaymentReversalRouteTest` for the full
     * explanation: a control or private-use character survives Laravel's
     * `TrimStrings` middleware, so before `NonBlankReason` was attached it
     * reached `Audit::record()` and surfaced as a 500 rather than a 422.
     */
    #[DataProvider('blankReasonsThatSurviveTrimStrings')]
    public function test_a_reason_the_audit_layer_calls_blank_is_rejected_as_validation_not_as_a_server_error(string $reason): void
    {
        $user = $this->authorizedUser();
        $verification = $this->submittedVerification();

        $response = $this->actingAs($user)
            ->post($this->verifyUrl($verification), [
                'decision' => 'approve',
                'reason' => $reason,
            ]);

        $this->assertNotSame(500, $response->getStatusCode());
        $response->assertSessionHasErrors('reason');

        $verification->refresh();
        $this->assertSame(PaymentVerificationStatus::Submitted, $verification->status());
        $this->assertSame(0, AuditEvent::query()->where('action', PaymentAuditActions::MANUAL_VERIFICATION)->count());
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function blankReasonsThatSurviveTrimStrings(): iterable
    {
        yield 'control character U+0001' => ["\u{0001}"];
        yield 'private-use character U+E000' => ["\u{E000}"];
        yield 'non-breaking space U+00A0' => ["\u{00A0}"];
    }

    public function test_an_invalid_decision_value_is_rejected_at_the_http_layer(): void
    {
        $user = $this->authorizedUser();
        $verification = $this->submittedVerification();

        $this->actingAs($user)
            ->post($this->verifyUrl($verification), [
                'decision' => 'definitely-approve',
                'reason' => 'Some reason',
            ])
            ->assertSessionHasErrors('decision');
    }

    public function test_an_unauthenticated_request_is_refused_by_the_auth_guard(): void
    {
        // No `login` named route exists anywhere in this repo yet (no
        // login UI has been built — same gap DisableMfaControllerTest's
        // suite never exercises either). Laravel's default `Authenticate`
        // middleware's `redirectTo()` calls `route('login')`
        // unconditionally on an unauthenticated request, which throws
        // RouteNotFoundException rather than silently letting the request
        // through — an honest failure, not a bypass. Whichever future task
        // builds the login UI turns this into a real redirect; until then
        // this test pins that the `auth` guard is genuinely evaluated
        // BEFORE this route's own logic ever runs.
        $verification = $this->submittedVerification();

        // Laravel's exception handler renders the RouteNotFoundException as
        // a server error response rather than letting it bubble out of the
        // HTTP kernel — asserted as "definitely not a 2xx success" rather
        // than a specific status, since the exact rendering of a missing
        // named route is a framework/environment detail this test does not
        // own.
        $response = $this->post($this->verifyUrl($verification), [
            'decision' => 'approve',
            'reason' => 'Proof matched provider statement',
        ]);

        $response->assertStatus(500);
        $this->assertSame(PaymentVerificationStatus::Submitted, $verification->fresh()->status());
    }

    public function test_the_route_is_registered_with_the_reauthentication_middleware(): void
    {
        $route = collect(app('router')->getRoutes())
            ->first(fn ($route) => $route->getName() === 'admin.payments.manual-verifications.verify');

        $this->assertNotNull($route);
        $this->assertTrue(
            collect($route->gatherMiddleware())->contains(
                fn (string $middleware): bool => str_starts_with($middleware, RequireRecentAuthentication::class.':')
            ),
            'admin.payments.manual-verifications.verify must be gated by RequireRecentAuthentication.'
        );
    }

    public function test_deciding_via_http_leaves_payment_sessions_untouched(): void
    {
        $user = $this->authorizedUser();
        $verification = $this->submittedVerification();

        $this->actingAs($user)->post($this->verifyUrl($verification), [
            'decision' => 'approve',
            'reason' => 'Proof matched provider statement',
        ]);

        $this->assertSame(0, PaymentSession::query()->count());
    }
}
