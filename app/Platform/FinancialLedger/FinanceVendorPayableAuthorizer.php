<?php

declare(strict_types=1);

namespace App\Platform\FinancialLedger;

use App\Platform\FinancialLedger\Contracts\VendorPayableAuthorizer;
use App\Platform\FinancialLedger\Exceptions\VendorPayableNotAuthorisedException;
use App\Platform\IdentityAccess\ActorContext;
use App\Platform\IdentityAccess\Scopes\Models\ScopeAssignment;
use App\Platform\IdentityAccess\Scopes\ScopeEntityType;
use App\Platform\IdentityAccess\Scopes\ScopeGrantLevel;

/**
 * Explicit vendor-payable policy: a real `finance` role from the server-side
 * actor context, plus an active privileged grant on THIS vendor — or, for a
 * genuinely unattended run, a context with no human on it at all.
 *
 * ---------------------------------------------------------------------------
 * The human path: `finance` only, and why not `restricted_admin` too
 * ---------------------------------------------------------------------------
 * `FinanceOrRestrictedAdminPayoutAuthorizer` accepts `finance` OR
 * `restricted_admin`, because `docs/security/rbac-matrix.md`'s "Payout/refund"
 * row names restricted admin explicitly. There is no vendor-payable row in that
 * matrix at all, and recognising a liability is not a payout — so no authority
 * grants restricted admins this, and the fail-closed reading is to withhold it.
 *
 * Flagged as a reviewable choice rather than presented as settled, exactly as
 * `FinanceReconciliationAuthorizer` does: if a human decides restricted admins
 * should recognise vendor liabilities, adding the constant to
 * `AUTHORISED_ROLES` is the whole change.
 *
 * Scope is `ScopeEntityType::VENDOR` — the same entity space the payout policy
 * uses, because the record is vendor-scoped — but note that holding this
 * permission grants nothing about payouts: `Actions\ManualPayout` asks a
 * DIFFERENT seam, which is the point of them being two interfaces.
 *
 * ---------------------------------------------------------------------------
 * The unattended path is deliberately scoped, not a default and not a hole
 * ---------------------------------------------------------------------------
 * A scheduled re-assessment is a real, legitimate trigger: a payable is opened
 * `held` while the dispute window is open, and re-assessed once it elapses.
 * Requiring a human role there would break the production path. So it gets its
 * OWN method, with three properties that keep it from becoming a bypass:
 *
 *  1. **It must be asked for explicitly.** The caller names
 *     `VendorPayableAssessmentTrigger::UnattendedAssessment`; nothing falls
 *     back to it, and the default is the human path (which fails closed).
 *  2. **It refuses when a human is present.** `ActorContext::isAuthenticated()`
 *     is the test. A queue worker or console command resolves a guest context;
 *     an HTTP request from a signed-in admin does not. So a person cannot
 *     launder their action through the system path, and an audit row reading
 *     `actorRole: system, actorRef: null` is TRUE by construction rather than
 *     by convention. That was the defect: the old code wrote exactly that row
 *     for every caller, human or not.
 *  3. **It returns a named constant**, not the caller's word for itself.
 *
 * ---------------------------------------------------------------------------
 * The human path fails closed, and that is not a bug to work around
 * ---------------------------------------------------------------------------
 * `ActorContext::$roles` now resolves real granted roles via
 * `actor_role_assignments` (merged in commit `3dbdcde`, lane L5), so
 * `authorize()` is genuinely enforcing: a human holding `finance` plus an
 * active privileged grant on THIS vendor passes, and one holding neither is
 * still refused. Identical in shape to
 * `ManualPayout`/`ResolveException`/`BulkFinancialExport`, which read the
 * same role seam.
 */
final class FinanceVendorPayableAuthorizer implements VendorPayableAuthorizer
{
    public const string FINANCE_ROLE = 'finance';

    /**
     * The role recorded in the audit trail for an unattended assessment. The
     * same literal `Audit` rows already carry, kept as a named constant so the
     * one place that may legitimately write it is greppable.
     */
    public const string SYSTEM_ROLE = 'system';

    /**
     * @var list<string>
     */
    private const array AUTHORISED_ROLES = [
        self::FINANCE_ROLE,
    ];

    public function authorize(ActorContext $actor, string $vendorId): string
    {
        $actorReference = $actor->identityReference;

        if ($actorReference === null) {
            throw VendorPayableNotAuthorisedException::forActorContext($vendorId);
        }

        $role = $this->roleFromContext($actor);

        if ($role === null) {
            throw VendorPayableNotAuthorisedException::forActorContext($vendorId);
        }

        $hasVendorGrant = ScopeAssignment::query()
            ->where('actor_identifier', (string) $actorReference)
            ->where('entity_type', ScopeEntityType::VENDOR)
            ->where('entity_id', $vendorId)
            ->where('grant_level', ScopeGrantLevel::PRIVILEGED)
            ->whereNull('revoked_at')
            ->exists();

        if (! $hasVendorGrant) {
            throw VendorPayableNotAuthorisedException::forActorContext($vendorId);
        }

        return $role;
    }

    public function authorizeUnattended(ActorContext $actor, string $vendorId): string
    {
        if ($actor->isAuthenticated()) {
            throw VendorPayableNotAuthorisedException::forHumanActorOnUnattendedPath($vendorId);
        }

        return self::SYSTEM_ROLE;
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
