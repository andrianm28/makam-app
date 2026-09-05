<?php

declare(strict_types=1);

namespace App\Domain\OrderWorkflow\Actions;

use App\Domain\OrderWorkflow\Exceptions\OrderPaymentOpeningNotAuthorisedException;
use App\Domain\OrderWorkflow\Models\Order;
use App\Platform\IdentityAccess\ActorContext;
use App\Platform\IdentityAccess\Roles\ActorRole;
use App\Platform\IdentityAccess\Roles\Models\ActorRoleAssignment;
use App\Platform\IdentityAccess\Scopes\Models\ScopeAssignment;
use App\Platform\IdentityAccess\Scopes\ScopeEntityType;

/**
 * Condition 4 of the payment guard: THIS ORDER has been authorized to open
 * for payment by an admin — not that the CURRENT caller personally holds
 * that authorization.
 *
 * ---------------------------------------------------------------------------
 * Fixed 18 Aug 2026 — the original version checked the wrong actor
 * ---------------------------------------------------------------------------
 * The first version of this class (mechanically copied from
 * `FinanceOrRestrictedAdminPayoutAuthorizer`, which correctly checks the
 * CALLING actor because a finance admin performs a payout themselves,
 * synchronously) required `$actor` — the person invoking
 * `OpenPaymentSession` right now — to already hold an ADMIN role plus a
 * `ScopeAssignment` for this order. That is correct for an admin-initiated
 * action. It is never satisfiable for the actual call site this condition
 * gates: `App\Livewire\Public\Booking\BookingWizard::openOnlinePayment()`,
 * a PUBLIC, unauthenticated component. A real customer's `ActorContext`
 * resolves to `ActorContext::guest()` (no roles, no identity reference) —
 * see `LocalUsersTableIdentityAccessAdapter::resolveActorContext(null)` —
 * so no customer could ever pass this condition. Online payment was
 * structurally unreachable from the public booking wizard for every real
 * customer, unconditionally, regardless of gate state or order status.
 *
 * This was never caught because the only test exercising the success path
 * (`BookingWizardOnlinePaymentTest::test_submitting_online_opens_a_session_
 * and_redirects_to_the_hosted_checkout`) simulates "the operator completes
 * their side" by calling `$this->actingAs($adminUser)` on the TEST'S OWN
 * session, then re-uses that SAME admin-authenticated session to also
 * simulate the customer's own click — so the test exercised "an admin
 * clicks pay," never "a guest customer clicks pay." Fixed alongside this
 * class (see that test's `operatorCompletes()`, and
 * `GuardPaymentSessionUpstreamTest::
 * test_condition_4_passes_for_a_guest_actor_once_an_admin_has_authorized_
 * the_order`, the new unit-level regression case).
 *
 * ---------------------------------------------------------------------------
 * Corrected mechanism
 * ---------------------------------------------------------------------------
 * An active (`revoked_at` null) `ScopeAssignment` for
 * `ScopeEntityType::ORDER` / `entity_id` = this order must exist, held by
 * an `actor_identifier` that currently holds a role permitted to grant this
 * transition (an active `ActorRoleAssignment` row, same "currently holds,"
 * not "held at grant time," posture the original check used for the
 * caller). This represents "an authorized staff member reviewed and
 * authorized THIS ORDER for payment" as a property of the order —
 * independent of who is now creating the payment session. `$actor` is
 * retained in the signature (call sites are unchanged, and a future
 * privileged-actor fast path may want it) but is no longer read.
 *
 * ---------------------------------------------------------------------------
 * FIXED 5 Sep 2026 — FINANCE-role grantors were silently excluded
 * ---------------------------------------------------------------------------
 * `App\Domain\OrderWorkflow\Authorization\OrderTransitionAuthorizer::
 * MONEY_TRANSITIONS` explicitly permits BOTH `ActorRole::ADMIN` and
 * `ActorRole::FINANCE` to perform the `authorize_payment_opening`
 * transition (`TransitionOrderAction::__invoke()` passes the real acting
 * finance staff member's own identity to `GrantOrderPaymentOpening`, which
 * correctly creates a real `ScopeAssignment` for them). But this class's
 * qualifying-grantor query only ever looked for `ActorRole::ADMIN` — a
 * finance-only staff member's own real, correctly-created grant was
 * invisible to it. The admin panel reported the transition as successful
 * (it was — `GrantOrderPaymentOpening`/`RecordOrderStatusChange` both
 * completed), but the customer's own `openOnlinePayment()` call then
 * failed condition 4 with no visible cause to the finance actor who
 * "already did this." Widened the qualifying-role set to match
 * `MONEY_TRANSITIONS`'s own real permitted-grantor set for this specific
 * transition, rather than re-deriving a second, narrower list here.
 */
final readonly class AuthorizeOrderPaymentOpening
{
    /** Mirrors OrderTransitionAuthorizer::MONEY_TRANSITIONS' own permitted
     * grantor roles for the `authorize_payment_opening` transition
     * specifically — not every MONEY_TRANSITIONS entry, just this one.
     *
     * @var list<string>
     */
    private const array QUALIFYING_GRANTOR_ROLES = [ActorRole::ADMIN, ActorRole::FINANCE];

    public function __invoke(ActorContext $actor, Order $order): string
    {
        $authorizingIds = ActorRoleAssignment::query()
            ->whereIn('role', self::QUALIFYING_GRANTOR_ROLES)
            ->whereNull('revoked_at')
            ->pluck('actor_identifier');

        $hasOrderGrant = ScopeAssignment::query()
            ->where('entity_type', ScopeEntityType::ORDER)
            ->where('entity_id', $order->getKey())
            ->whereIn('actor_identifier', $authorizingIds)
            ->whereNull('revoked_at')
            ->exists();

        if (! $hasOrderGrant) {
            throw OrderPaymentOpeningNotAuthorisedException::forOrder($order->getKey());
        }

        return ActorRole::ADMIN;
    }
}
