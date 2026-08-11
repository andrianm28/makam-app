<?php

declare(strict_types=1);

namespace App\Platform\FinancialLedger;

use App\Platform\FinancialLedger\Contracts\PayoutAuthorizer;
use App\Platform\FinancialLedger\Exceptions\PayoutNotAuthorisedException;
use App\Platform\IdentityAccess\ActorContext;
use App\Platform\IdentityAccess\Scopes\Models\ScopeAssignment;
use App\Platform\IdentityAccess\Scopes\ScopeEntityType;
use App\Platform\IdentityAccess\Scopes\ScopeGrantLevel;

/**
 * Explicit payout policy: a real finance or restricted-admin role from the
 * server-side actor context plus an active, vendor-scoped privileged grant.
 *
 * A generic privileged grant alone is not enough, and an empty role list is
 * not interpreted as permission. The current local identity adapter exposes
 * no real roles, so this policy intentionally refuses until that seam is
 * backed by an authoritative identity source.
 */
final class FinanceOrRestrictedAdminPayoutAuthorizer implements PayoutAuthorizer
{
    public const string FINANCE_ROLE = 'finance';

    public const string RESTRICTED_ADMIN_ROLE = 'restricted_admin';

    /**
     * @var list<string>
     */
    private const array AUTHORISED_ROLES = [
        self::FINANCE_ROLE,
        self::RESTRICTED_ADMIN_ROLE,
    ];

    public function authorize(ActorContext $actor, string $vendorId): string
    {
        $actorReference = $actor->identityReference;

        if ($actorReference === null) {
            throw PayoutNotAuthorisedException::forActorContext($vendorId);
        }

        $role = $this->roleFromContext($actor);

        if ($role === null) {
            throw PayoutNotAuthorisedException::forActorContext($vendorId);
        }

        $hasVendorGrant = ScopeAssignment::query()
            ->where('actor_identifier', (string) $actorReference)
            ->where('entity_type', ScopeEntityType::VENDOR)
            ->where('entity_id', $vendorId)
            ->where('grant_level', ScopeGrantLevel::PRIVILEGED)
            ->whereNull('revoked_at')
            ->exists();

        if (! $hasVendorGrant) {
            throw PayoutNotAuthorisedException::forActorContext($vendorId);
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
