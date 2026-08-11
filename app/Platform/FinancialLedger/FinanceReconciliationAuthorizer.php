<?php

declare(strict_types=1);

namespace App\Platform\FinancialLedger;

use App\Platform\FinancialLedger\Contracts\ReconciliationAuthorizer;
use App\Platform\FinancialLedger\Exceptions\ReconciliationNotAuthorisedException;
use App\Platform\IdentityAccess\ActorContext;
use App\Platform\IdentityAccess\Scopes\Models\ScopeAssignment;
use App\Platform\IdentityAccess\Scopes\ScopeEntityType;
use App\Platform\IdentityAccess\Scopes\ScopeGrantLevel;

/**
 * Explicit reconciliation policy: a real `finance` role from the server-side
 * actor context, plus an active privileged grant on THIS badan usaha.
 *
 * ---------------------------------------------------------------------------
 * Why this is narrower than the payout policy, on both axes
 * ---------------------------------------------------------------------------
 * `FinanceOrRestrictedAdminPayoutAuthorizer` accepts `finance` OR
 * `restricted_admin`, scoped to a VENDOR. This one accepts `finance` only,
 * scoped to a BUSINESS ENTITY. Both differences are deliberate:
 *
 *  - **Role.** Task 5's brief calls for "a finance-scoped policy", and
 *    `docs/security/rbac-matrix.md`'s "Payout/refund" row is the only row that
 *    names restricted admin for a money action — there is no reconciliation row
 *    in that matrix at all. With no authority granting restricted admins
 *    reconciliation-decision rights, the fail-closed reading is to withhold
 *    them. Flagged as a reviewable choice rather than presented as settled: if
 *    a human decides restricted admins should decide variances, adding the
 *    constant to `AUTHORISED_ROLES` is the whole change.
 *  - **Scope entity.** A reconciliation covers a badan usaha's books, so the
 *    grant is checked against `ScopeEntityType::BUSINESS_ENTITY` with the
 *    reconciliation's own `entity_ref`. This is what structurally keeps the two
 *    authorities apart: a privileged VENDOR grant — the thing that authorises a
 *    payout — is not a business-entity grant and never satisfies this check.
 *
 * ---------------------------------------------------------------------------
 * It fails closed today, and that is not a bug to work around
 * ---------------------------------------------------------------------------
 * The current local identity adapter exposes no real roles at all
 * (`ActorContext::$roles` is always `[]` — see that class's own doc block), so
 * this policy refuses every real request until that seam is backed by an
 * authoritative identity source. An empty role list is not interpreted as
 * permission, and a generic privileged grant alone is not enough either.
 */
final class FinanceReconciliationAuthorizer implements ReconciliationAuthorizer
{
    public const string FINANCE_ROLE = 'finance';

    /**
     * @var list<string>
     */
    private const array AUTHORISED_ROLES = [
        self::FINANCE_ROLE,
    ];

    public function authorize(ActorContext $actor, string $entityRef): string
    {
        $actorReference = $actor->identityReference;

        if ($actorReference === null) {
            throw ReconciliationNotAuthorisedException::forActorContext($entityRef);
        }

        $role = $this->roleFromContext($actor);

        if ($role === null) {
            throw ReconciliationNotAuthorisedException::forActorContext($entityRef);
        }

        $hasEntityGrant = ScopeAssignment::query()
            ->where('actor_identifier', (string) $actorReference)
            ->where('entity_type', ScopeEntityType::BUSINESS_ENTITY)
            ->where('entity_id', $entityRef)
            ->where('grant_level', ScopeGrantLevel::PRIVILEGED)
            ->whereNull('revoked_at')
            ->exists();

        if (! $hasEntityGrant) {
            throw ReconciliationNotAuthorisedException::forActorContext($entityRef);
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
