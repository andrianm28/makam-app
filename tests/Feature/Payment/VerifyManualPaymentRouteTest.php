<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Domain\Marketplace\Models\MarketplaceOrder;
use App\Domain\Marketplace\Models\Vendor;
use App\Domain\Marketplace\PaymentState;
use App\Http\Middleware\RequireRecentAuthentication;
use App\Models\User;
use App\Platform\Audit\AuditOutcome;
use App\Platform\Audit\Models\AuditEvent;
use App\Platform\FinancialLedger\Actions\VendorPayable;
use App\Platform\FinancialLedger\Money;
use App\Platform\FinancialLedger\VendorPayableAssessmentTrigger;
use App\Platform\FinancialLedger\VendorPayableEligibility;
use App\Platform\IdentityAccess\ActorContext;
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
 * real attachment anywhere in this repo. Proves the middleware's own
 * fail-closed-on-a-null-timestamp behaviour (see `RequireRecentAuthentication`'s
 * own doc block) against a real route, not a test fixture route.
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

    private const int TOTAL_MINOR = 325_000_00;

    /**
     * A real marketplace order (BELUM_DIBAYAR, vendor payable opened HELD —
     * same fixture shape `VerifyManualPaymentTest`/`WebhookPaidEffectsTest`
     * already establish) plus a SUBMITTED `payment_verifications` row linked
     * to it with a matching stated amount. Real linkage is required since
     * PAY-02: `VerifyManualPayment` refuses to approve a row with no
     * `order_id`/`amount_minor`, and this route test's whole purpose is
     * proving the AUTHORIZATION gate, not re-proving PAY-02's own amount
     * logic (`VerifyManualPaymentTest` owns that) — so every fixture here
     * uses a matching amount and every approval in this file is expected to
     * succeed on the merits once authorized.
     */
    private function submittedVerification(): PaymentVerification
    {
        $vendor = Vendor::query()->create(['name' => 'Toko Bunga', 'is_active' => true]);

        $order = MarketplaceOrder::query()->create([
            'order_number' => 'MKT-'.Str::upper(Str::random(10)),
            'customer_ref' => 'cust-1',
            'entity_ref' => 'BU-JKT-01',
            'vendor_id' => $vendor->id,
            'subtotal_minor' => self::TOTAL_MINOR,
            'delivery_fee_minor' => 0,
            'total_minor' => self::TOTAL_MINOR,
            'payment_state' => PaymentState::BELUM_DIBAYAR,
            'idempotency_key' => 'mkt-'.Str::lower(Str::random(12)),
            'placed_at' => CarbonImmutable::now(),
        ]);

        (new VendorPayable(actorContext: ActorContext::guest()))->assess(
            vendorId: $vendor->id,
            entityRef: 'BU-JKT-01',
            sourceType: 'marketplace_order',
            sourceId: $order->id,
            amount: new Money(self::TOTAL_MINOR),
            eligibility: new VendorPayableEligibility(
                orderPaid: false,
                fulfilmentEvidenceAccepted: false,
                disputeWindowEndsAt: null,
            ),
            trigger: VendorPayableAssessmentTrigger::UnattendedAssessment,
            now: CarbonImmutable::now(),
        );

        return PaymentVerification::createSubmitted([
            'reference' => $order->order_number,
            'order_id' => $order->id,
            'amount_minor' => self::TOTAL_MINOR,
            'currency' => 'IDR',
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

    /**
     * The trace every refusal must leave, asserted in one place so the two
     * halves of this hotfix cannot drift: exactly `$expected` `Denied` audit
     * rows naming this endpoint, no `Allowed` row anywhere, and none of the
     * downstream writes.
     *
     * This replaces the older `AuditEvent::query()->count() === 0` shape,
     * which froze "a refused money-moving action leaves no trace" into the
     * test suite (review finding SF-6). What keeps it non-vacuous:
     *
     * - the count is EXACT, so deleting the audit write and double-writing it
     *   both fail. A `>= 1` assertion would catch neither.
     * - the `Allowed` count is asserted separately, so the refusal path
     *   cannot start minting `satisfied`/`Allowed` rows and still pass by
     *   virtue of having produced a `Denied` one too.
     * - each row's own fields are pinned, so a write recording the wrong
     *   action, the wrong subject, or the caller's payload fails here rather
     *   than silently counting as "audited". In particular the subject must
     *   NOT be the caller-supplied `{paymentVerification}` id.
     *
     * `$actor` and `$expectedRole` are REQUIRED, not optional, because of
     * whole-branch review findings SF-4 and SF-5. Nothing pinned `actor_ref`
     * before, so setting it to null in `RecordPaymentActionRefusal` left the
     * whole payment suite green — and a refusal row with no actor reference is
     * a counter, not a monitoring signal: it records that something was
     * refused and nothing about who. `actor_role` is pinned in the same place
     * and for the same reason: the row must name the refused actor's REAL
     * role, so that "a `customer` is probing this endpoint" and "an `admin`
     * needs a grant" stay distinguishable in the trail. Making both parameters
     * mandatory is deliberate — an optional assertion is one a future caller
     * silently skips.
     */
    private function assertTheRefusalWasAuditedExactly(
        User $actor,
        string $expectedRole,
        int $expected = 1,
        ?string $unknownId = null,
    ): void {
        $denials = AuditEvent::query()
            ->where('action', PaymentAuditActions::ADMIN_ACTION_DENIED)
            ->get();

        $this->assertCount($expected, $denials);

        foreach ($denials as $denial) {
            $this->assertSame(AuditOutcome::Denied->value, $denial->outcome);
            $this->assertSame('payment_admin_action', $denial->subject_type);
            $this->assertSame('payment_manual_verification', $denial->subject_id);

            // SF-4: who was refused. The single field that makes this row a
            // monitoring signal rather than a counter.
            $this->assertSame((string) $actor->id, (string) $denial->actor_ref);

            // SF-5: the refused actor's real, most-privileged held role — the
            // `authenticated_actor` sentinel ONLY when they truly hold none.
            $this->assertSame($expectedRole, $denial->actor_role);

            // A fixed server-side reason, never the caller's text —
            // authorization runs before validation, so the request body is
            // unvalidated here.
            $this->assertNotNull($denial->reason);
            $this->assertStringNotContainsString('Proof matched', (string) $denial->reason);
            $this->assertSame([], $denial->metadata);

            if ($unknownId !== null) {
                // The caller-chosen route segment must appear nowhere in the
                // row: writing it would put an unbounded attacker-supplied
                // value in `audit_events.subject_id` and would re-open, in
                // the audit trail, the existence question the ordering
                // closes on the response.
                $this->assertNotSame($unknownId, (string) $denial->subject_id);
                $this->assertStringNotContainsString($unknownId, (string) $denial->reason);
            }
        }

        $this->assertSame(
            0,
            AuditEvent::query()
                ->where('outcome', AuditOutcome::Allowed->value)
                // Excludes `VENDOR_PAYABLE_ASSESSED` — since PAY-02,
                // `submittedVerification()` opens a real marketplace order's
                // vendor payable as part of building a REALISTIC fixture (the
                // same setup a real checkout performs), and that legitimately
                // writes its own Allowed audit row before the HTTP call this
                // assertion is checking even happens. What this assertion
                // still proves, unweakened: a refused actor collects no
                // Allowed row FOR THIS ENDPOINT's own decision.
                ->where('action', '!=', 'VENDOR_PAYABLE_ASSESSED')
                ->count(),
            'A refused actor must not collect a single Allowed audit row.',
        );
        $this->assertSame(
            0,
            PaymentVerification::query()->whereNotNull('decided_at')->count(),
            'A refused actor must not have decided any verification.',
        );
        $this->assertSame(0, ReauthenticationEvent::query()->count());
    }

    public function test_verification_without_a_fresh_authentication_redirects_to_the_challenge_and_changes_nothing(): void
    {
        $user = User::factory()->create();
        $this->grantRole($user, ActorRole::FINANCE);
        $verification = $this->submittedVerification();

        // Deliberately NOT creating an actor_sessions row with a recent
        // last_authenticated_at — RequireRecentAuthentication fails closed
        // on a null timestamp, by design (see that middleware's own doc
        // block). The role IS granted so this still pins the middleware
        // specifically: the redirect cannot be the authorization refusal
        // wearing the middleware's clothes.
        $this->actingAs($user)
            ->post($this->verifyUrl($verification), [
                'decision' => 'approve',
                'reason' => 'Proof matched provider statement',
            ])
            ->assertRedirect(route('filament.admin.pages.verifikasi-ulang-kata-sandi'));

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

        // The sentinel is correct HERE and only here: this actor genuinely
        // holds no role at all.
        $this->assertTheRefusalWasAuditedExactly($user, 'authenticated_actor');
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

        // And the refusal row names the role they DO hold. Recording the
        // sentinel here would erase the difference between someone probing
        // this endpoint from a customer account and an operator awaiting a
        // grant — review finding SF-5.
        $this->assertTheRefusalWasAuditedExactly($user, ActorRole::CUSTOMER);
    }

    /**
     * The refusal the operator will actually see after this merges.
     *
     * `admin` is deliberately NOT on the authorized pair (plan D1), so the
     * first person to hit this 403 in production is very likely an existing
     * admin who has not been granted `finance` or `restricted_admin` yet.
     * That case must be distinguishable in the trail from a genuinely
     * anonymous or role-less actor, which is the entire point of SF-5: one is
     * "grant this person a role", the other is "investigate".
     */
    public function test_a_refused_admin_is_recorded_under_their_real_role_not_the_sentinel(): void
    {
        $user = $this->authorizedUser(ActorRole::ADMIN);
        $verification = $this->submittedVerification();

        $this->actingAs($user)
            ->post($this->verifyUrl($verification), [
                'decision' => 'approve',
                'reason' => 'Proof matched provider statement',
            ])
            ->assertForbidden();

        $this->assertSame(PaymentVerificationStatus::Submitted, $verification->fresh()->status());
        $this->assertTheRefusalWasAuditedExactly($user, ActorRole::ADMIN);
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

        // A revoked grant is not a held role: `ActorRoleReader` filters
        // `whereNull('revoked_at')`, so this actor holds none and the sentinel
        // is the honest value.
        $this->assertTheRefusalWasAuditedExactly($user, 'authenticated_actor');
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

        $unknownId = (string) Str::uuid();

        $unknownUrl = route(
            'admin.payments.manual-verifications.verify',
            ['paymentVerification' => $unknownId],
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

        // Two refusals, two rows — and neither row carries the fabricated id,
        // so the audit trail is not an existence oracle either.
        $this->assertTheRefusalWasAuditedExactly($user, 'authenticated_actor', expected: 2, unknownId: $unknownId);
    }

    /**
     * The other half of the reordering, and the one nothing else pinned: an
     * AUTHORIZED actor posting a fabricated id must still get the ordinary
     * 404 from `PaymentVerification::findOrFail()`.
     *
     * Without this, moving `authorize()` above the lookup could have been
     * "verified" by a suite in which the legitimate not-found path had
     * quietly stopped working — every other test either supplies a real id or
     * is refused before the lookup is reached. The 403-vs-404 asymmetry is
     * the intended design: it is keyed on the actor's authority, never on
     * whether the record exists.
     */
    public function test_an_unknown_verification_id_is_still_404_for_an_authorized_actor(): void
    {
        $user = $this->authorizedUser();

        $unknownUrl = route(
            'admin.payments.manual-verifications.verify',
            ['paymentVerification' => (string) Str::uuid()],
        );

        $this->actingAs($user)
            ->post($unknownUrl, [
                'decision' => 'approve',
                'reason' => 'Proof matched provider statement',
            ])
            ->assertNotFound();

        // Authorized, so nothing was refused and no refusal row exists — the
        // 404 is the lookup's, not the authorizer's wearing a different code.
        $this->assertSame(0, AuditEvent::query()->where('action', PaymentAuditActions::ADMIN_ACTION_DENIED)->count());
        $this->assertSame(0, AuditEvent::query()->where('action', PaymentAuditActions::MANUAL_VERIFICATION)->count());
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

        // And the refusal is still audited even though nothing about the
        // request was ever validated — the refusal row carries no part of it,
        // while still naming who was refused.
        $this->assertTheRefusalWasAuditedExactly($user, 'authenticated_actor');
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
        // Updated by the `/akun` account area's auth-foundation PR
        // (`.superpowers/sdd/2026-08-20-akun-auth-foundation/`) — this is
        // the "whichever future task builds the login UI" this test's own
        // comment used to anticipate. A real `login` named route now
        // exists, so Laravel's default `Authenticate` middleware's
        // `redirectTo()` resolves `route('login')` successfully instead of
        // throwing `RouteNotFoundException`: an unauthenticated request now
        // gets a clean redirect rather than an incidental 500. That
        // redirect (not a crash) is what actually proves the `auth` guard
        // is evaluated BEFORE this route's own logic ever runs.
        $verification = $this->submittedVerification();

        $response = $this->post($this->verifyUrl($verification), [
            'decision' => 'approve',
            'reason' => 'Proof matched provider statement',
        ]);

        $response->assertRedirect(route('login'));
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

    /**
     * `throttle:payment-manual-verification` (public-beta-release plan,
     * Lane D4) — 5/minute per actor+ip. Reuses the "without a fresh
     * authentication" fixture (same $verification, same $user, repeated):
     * that path is side-effect-free (redirects to the MFA challenge,
     * changes nothing), so it is safe to call 6 times in one test without
     * the underlying business logic itself rejecting a repeat call for an
     * unrelated reason.
     */
    public function test_the_route_is_rate_limited(): void
    {
        $user = User::factory()->create();
        $this->grantRole($user, ActorRole::FINANCE);
        $verification = $this->submittedVerification();

        for ($i = 0; $i < 5; $i++) {
            $this->actingAs($user)
                ->post($this->verifyUrl($verification), [
                    'decision' => 'approve',
                    'reason' => 'Proof matched provider statement',
                ])
                ->assertRedirect(route('filament.admin.pages.verifikasi-ulang-kata-sandi'));
        }

        $this->actingAs($user)
            ->post($this->verifyUrl($verification), [
                'decision' => 'approve',
                'reason' => 'Proof matched provider statement',
            ])
            ->assertStatus(429);
    }
}
