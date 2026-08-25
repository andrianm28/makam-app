<?php

declare(strict_types=1);

namespace App\Platform\IdentityAccess\MasterData;

use App\Platform\IdentityAccess\ActorContext;
use App\Platform\IdentityAccess\MasterData\Contracts\MasterDataAdminAuthorizerContract;
use App\Platform\IdentityAccess\MasterData\Exceptions\MasterDataNotAuthorisedException;
use App\Platform\IdentityAccess\Roles\ActorRole;

/**
 * The shared master-data administration policy: any of the four back-office
 * roles (admin, restricted_admin, operator, finance) may administer the
 * master-data entities; everyone else — including a bare authenticated
 * customer — is refused.
 *
 * ---------------------------------------------------------------------------
 * Why a role check, and why THIS role list
 * ---------------------------------------------------------------------------
 * Master data is platform-wide: there is no record scope to check, unlike
 * the entity-scoped financial authorizers. The four-role allow-list is the
 * union of every role `docs/security/rbac-matrix.md` grants back-office
 * administration, and the spec's "four back-office roles yes, bare customer
 * no" contract. The list is one-line-extensible (same shape as
 * `FinanceReconciliationAuthorizer::AUTHORISED_ROLES`).
 *
 * ---------------------------------------------------------------------------
 * It fails closed, and that is not a bug to work around
 * ---------------------------------------------------------------------------
 * An empty role list is not interpreted as permission, and a null identity
 * reference (guest) is refused before the role list is even consulted.
 */
final class MasterDataAdminAuthorizer implements MasterDataAdminAuthorizerContract
{
    /**
     * @var list<string>
     */
    private const array AUTHORISED_ROLES = [
        ActorRole::ADMIN,
        ActorRole::RESTRICTED_ADMIN,
        ActorRole::OPERATOR,
        ActorRole::FINANCE,
    ];

    public function authorize(ActorContext $actor): void
    {
        if ($actor->identityReference === null) {
            throw MasterDataNotAuthorisedException::forActorContext();
        }

        foreach (self::AUTHORISED_ROLES as $role) {
            if ($actor->hasRole($role)) {
                return;
            }
        }

        throw MasterDataNotAuthorisedException::forActorContext();
    }
}
