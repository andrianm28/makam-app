<?php

declare(strict_types=1);

namespace App\Platform\Audit;

use App\Platform\Audit\Contracts\AuditReadAuthorizer;
use App\Platform\Audit\Exceptions\AuditReadNotAuthorisedException;
use App\Platform\IdentityAccess\ActorContext;
use App\Platform\IdentityAccess\Roles\ActorRole;
use App\Platform\IdentityAccess\Roles\RoleAuditActions;
use App\Platform\IdentityAccess\Scopes\ScopeAuditActions;

/**
 * `admin` and `restricted_admin` — and only those two — may read the audit
 * trail; `restricted_admin` sees it with the privilege-escalation actions
 * withheld.
 *
 * ---------------------------------------------------------------------------
 * Why these two roles, and why the split between them
 * ---------------------------------------------------------------------------
 * `docs/security/rbac-matrix.md` has no "audit trail review" row, so — same
 * situation `Domain\Faq\Authorization\RoleBasedFaqAuthorizer` documents for
 * FAQ management — the authoritative answer to "who may review the audit
 * trail?" does not exist yet. Faced with an unspecified boundary this class
 * takes the most restrictive defensible position available in this
 * codebase's own precedent, not a blanket "every back-office role" grant:
 * granting too narrowly produces a support ticket, granting too widely
 * produces an unnoticed authorization defect (an audit trail that itself
 * leaks who did what to anyone with any admin-panel role would defeat AC8's
 * "dedicated authorization ... for sensitive actions" for the very table
 * that records that dedication).
 *
 * `admin` is the obvious base case: it is `ActorRole::KNOWN_ROLES`'s
 * most-privileged entry and rbac-matrix's "Feature/capability gate" row
 * — the closest existing analogue to "review a platform-wide sensitive
 * control" — gives Admin "Dedicated privileged".
 *
 * `restricted_admin` is included, scoped, rather than excluded outright,
 * because this codebase already treats it as the adjacent tier for
 * similarly-sensitive write surfaces: `CertificatesResource`'s doc block
 * names "admin + restricted_admin may issue/revoke/replace" for certificate
 * lifecycle actions, and `FinanceOrRestrictedAdminPaymentAuthorizer`/
 * `FinanceOrRestrictedAdminPayoutAuthorizer` both pair `restricted_admin`
 * with `finance` for money-moving attestations. Excluding it here while
 * admitting it to write surfaces of comparable sensitivity would be the
 * inconsistency `DocumentAccessPolicy`'s own doc block warns against
 * ("admitting one while excluding the other on the same cell value").
 *
 * The exclusion list itself — `RoleAuditActions::GRANT`/`REVOKE`,
 * `ScopeAuditActions::GRANT`/`REVOKE`, and equivalent privilege-escalation
 * actions — is every action `SensitiveActions::ACTIONS` describes as
 * "privilege-escalation category" (actions that change who holds authorization
 * power). Those rows record who can grant or revoke the very authority this
 * authorizer itself is deciding — an actor one tier below `admin` reviewing
 * the trail of who has been granted admin-adjacent power is exactly the kind
 * of visibility that tier split exists to withhold. `restricted_admin` still
 * sees every other sensitive action: payment reversals, payouts, certificate
 * revocations, plot overrides, and so on — none of those change who holds
 * authorization power, only what an already-authorized actor did with it.
 *
 * `finance`, `operator`, `case_manager`, `vendor`, `customer`, and `system`
 * are excluded. None has an affirmative grant in any existing rbac-matrix
 * row for a platform-wide sensitive-action trail; `finance`'s existing
 * grants (`FinanceLedgerReadAuthorizer`, the two payment/payout
 * authorizers) are all scoped to money-moving actions on badan-usaha-scoped
 * records, not to reading every domain's audit history. This exclusion is
 * flagged for human confirmation the same way `RoleBasedFaqAuthorizer`
 * flags its own single-role choice — it is the narrowest defensible
 * reading of an unspecified boundary, not a claim that no other role should
 * ever be added.
 *
 * ---------------------------------------------------------------------------
 * Why this is role-only, with no `scope_assignments` grant check
 * ---------------------------------------------------------------------------
 * See `Contracts\AuditReadAuthorizer`'s own doc block — `audit_events` has
 * no column in the `scope_assignments.entity_id` value space, the same
 * situation `Payment\FinanceOrRestrictedAdminPaymentAuthorizer` documents
 * for `payment_reversals`/`payment_verifications`, which rbac-matrix names
 * as the deliberate narrow exception to its "role and scope grant" rule.
 *
 * Stateless by construction: the `ActorContext` under judgement arrives as a
 * method parameter, freshly, per call — never captured in a constructor
 * property. Same shape as every other authorizer in this codebase, for the
 * same stale-actor-in-a-worker reason `FinancialLedgerServiceProvider`
 * documents.
 */
final class RoleBasedAuditReadAuthorizer implements AuditReadAuthorizer
{
    /**
     * Declaration order is `ActorRole::KNOWN_ROLES` precedence order, most
     * privileged first, so an actor holding both roles is recorded under
     * the more privileged one.
     *
     * @var list<string>
     */
    private const array AUTHORISED_ROLES = [
        ActorRole::ADMIN,
        ActorRole::RESTRICTED_ADMIN,
    ];

    /**
     * The actions withheld from `restricted_admin` — see this class's own
     * doc block for why exactly these five and no others.
     *
     * @var list<string>
     */
    private const array PRIVILEGE_ESCALATION_ACTIONS = [
        RoleAuditActions::GRANT,
        RoleAuditActions::REVOKE,
        ScopeAuditActions::GRANT,
        ScopeAuditActions::REVOKE,
        // Literal string, not a class constant — historical `MFA_RESET`
        // rows remain in `audit_events` (that table was never dropped) from
        // before the MFA module was removed, and must stay excluded from
        // `restricted_admin`'s read scope exactly as before.
        'MFA_RESET',
    ];

    public function authorize(ActorContext $actor): AuditReadScope
    {
        $actorReference = $actor->identityReference;

        if ($actorReference === null || $actorReference === '') {
            throw AuditReadNotAuthorisedException::forActorContext();
        }

        $role = $this->roleFromContext($actor);

        if ($role === null) {
            throw AuditReadNotAuthorisedException::forActorContext();
        }

        if ($role === ActorRole::ADMIN) {
            return new AuditReadScope(role: $role, excludedActions: []);
        }

        $excluded = self::PRIVILEGE_ESCALATION_ACTIONS;
        sort($excluded);

        return new AuditReadScope(role: $role, excludedActions: $excluded);
    }

    private function roleFromContext(ActorContext $actor): ?string
    {
        foreach (self::AUTHORISED_ROLES as $role) {
            if ($actor->hasRole($role)) {
                return $role;
            }
        }

        return null;
    }
}
