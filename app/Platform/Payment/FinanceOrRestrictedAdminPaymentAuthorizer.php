<?php

declare(strict_types=1);

namespace App\Platform\Payment;

use App\Platform\IdentityAccess\ActorContext;
use App\Platform\IdentityAccess\Roles\ActorRole;
use App\Platform\Payment\Contracts\PaymentActionAuthorizer;
use App\Platform\Payment\Exceptions\PaymentActionNotAuthorisedException;

/**
 * Explicit policy for the two money-moving payment admin actions: a real
 * `finance` or `restricted_admin` role on the server-resolved actor context.
 * An absent identity is refused, and an empty role list is never read as
 * permission.
 *
 * The role pair is `docs/security/rbac-matrix.md`'s "Payout/refund" row
 * (`Admin: Restricted, Finance: Dedicated finance`) — the same pair
 * `FinancialLedger\FinanceOrRestrictedAdminPayoutAuthorizer` already
 * enforces. No third role is invented here.
 *
 * ---------------------------------------------------------------------------
 * Why this is role-only, with NO `scope_assignments` grant check
 * ---------------------------------------------------------------------------
 * All four authorizers in `app/Platform/FinancialLedger/` pair the role test
 * with an active, record-scoped `ScopeAssignment` lookup, so the absence of
 * one here is a deliberate divergence that has to earn itself. It is a
 * property of these two tables, not a laxer policy:
 *
 * - **Neither table has a scopeable column.** `payment_reversals` holds
 *   `id, reversal_type, reference, amount_minor, reason,
 *   recorded_by_actor_ref, recorded_at`; `payment_verifications` holds
 *   `id, reference, payment_method, payment_reference, instructions,
 *   proof_document_id, status, submitted_at, decided_*`. Nothing in either
 *   is in the `scope_assignments.entity_id` value space — there is no
 *   vendor, order, case, cemetery, grave, or business-entity reference to
 *   scope against. Both migrations record that these tables deliberately
 *   carry no foreign key to `payment_sessions` or to any order/booking
 *   table.
 * - **The one candidate column is explicitly not a key, and is chosen by the
 *   caller.** The reversals migration annotates `reference` as free-text
 *   external reference, caller-supplied, NOT a foreign key — and the client
 *   supplies it in the POST body. An authorization check whose compared key
 *   is picked by the attacker is not a control: it would let the holder of
 *   any single grant forge a matching `reference` and pass. Scoping on it
 *   would be worse than not scoping at all.
 *
 * Hence the contract's `authorize(ActorContext $actor): string` signature
 * with no scope parameter — see `Contracts\PaymentActionAuthorizer`.
 *
 * ---------------------------------------------------------------------------
 * Where this is invoked, and why not inside the write APIs
 * ---------------------------------------------------------------------------
 * The two HTTP controllers call this FIRST, before
 * `ReauthenticationService::satisfy()`, before request validation, and
 * before any record lookup, translating a refusal into `abort(403)`. The
 * established convention elsewhere is Action-level (`ManualPayout::pay()`
 * authorises internally); this module places the check at the controller
 * boundary instead because each write API's only production caller is its
 * own controller, because `ReversalService` is a documented thin dispatcher
 * whose single responsibility injecting a policy would contradict, and
 * because `abort(403)` already lives at the controller boundary in this app
 * (`Http\Controllers\Admin\FinanceExportController`) — there is no
 * framework-level exception mapping to lean on.
 *
 * Residual risk, stated rather than silently carried: `ReversalService
 * ::record()` and `VerifyManualPayment::verify()` still accept a
 * caller-supplied `actorRole` that lands unchecked in the audit row. Not
 * exploitable today (no other production caller exists), but a future second
 * caller would reopen it.
 *
 * ---------------------------------------------------------------------------
 * This is live enforcement, not an inert placeholder
 * ---------------------------------------------------------------------------
 * `ActorContext::$roles` carries REAL grant data: `Adapters
 * \LocalUsersTableIdentityAccessAdapter` resolves an actor's active,
 * non-revoked `actor_role_assignments` rows through `Roles\ActorRoleReader`.
 * So this policy genuinely admits the finance and restricted-admin actors
 * who hold a grant, and genuinely refuses everyone else, from the moment it
 * is bound.
 *
 * Two stale claims are deliberately NOT repeated here. The hotfix plan's §6
 * describes `$roles` as permanently `[]`, and each of the four
 * `FinancialLedger` authorizers still says the same in its own doc block;
 * both predate the lane that replaced those hardcoded placeholders. This
 * class copies their structure, not that sentence, and does not describe
 * itself as failing closed for everyone — it is not.
 *
 * What remains true, and is the reason the refusal path is the important
 * one: an empty role list means "this actor holds no grants today," never
 * "no role required." A caller must never read emptiness as permission.
 */
final class FinanceOrRestrictedAdminPaymentAuthorizer implements PaymentActionAuthorizer
{
    /**
     * Referenced from `Roles\ActorRole` rather than redeclared as literals:
     * that class is the canonical closed list and postdates the four
     * `FinancialLedger` authorizers, which still carry their own
     * `FINANCE_ROLE`/`RESTRICTED_ADMIN_ROLE` constants from before it
     * existed. `AGENTS.md` §Documentation forbids duplicating canonical
     * data, so this module does not add a fifth copy.
     *
     * Order is `ActorRole::KNOWN_ROLES` precedence order, most privileged
     * first, so an actor holding both roles is recorded under the more
     * privileged one.
     *
     * @var list<string>
     */
    private const array AUTHORISED_ROLES = [
        ActorRole::RESTRICTED_ADMIN,
        ActorRole::FINANCE,
    ];

    public function authorize(ActorContext $actor): string
    {
        $actorReference = $actor->identityReference;

        if ($actorReference === null) {
            throw PaymentActionNotAuthorisedException::forActorContext();
        }

        $role = $this->roleFromContext($actor);

        if ($role === null) {
            throw PaymentActionNotAuthorisedException::forActorContext($actorReference);
        }

        return $role;
    }

    private function roleFromContext(ActorContext $actor): ?string
    {
        foreach (self::AUTHORISED_ROLES as $role) {
            if (in_array($role, $actor->roles, true)) {
                return $role;
            }
        }

        return null;
    }
}
