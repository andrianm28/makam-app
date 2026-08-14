<?php

declare(strict_types=1);

namespace App\Platform\IdentityAccess\MasterData\Contracts;

use App\Platform\IdentityAccess\ActorContext;
use App\Platform\IdentityAccess\MasterData\Exceptions\MasterDataNotAuthorisedException;

/**
 * The policy seam for "may this actor administer the shared master-data
 * entities" (cemeteries + packages, products, service definitions + prices)
 * via the Filament admin panel.
 *
 * ---------------------------------------------------------------------------
 * What it does and does not claim
 * ---------------------------------------------------------------------------
 * It is a ROLE check against the four back-office roles
 * (`ActorRole::ADMIN`, `RESTRICTED_ADMIN`, `OPERATOR`, `FINANCE`), shared by
 * every master-data resource so one change to the role list re-gates them
 * all. It deliberately checks no per-record scope: master data is
 * platform-wide by design, so there is no `entity_ref` — unlike the
 * entity-scoped financial authorizers.
 *
 * ---------------------------------------------------------------------------
 * Fail-closed discipline
 * ---------------------------------------------------------------------------
 * An empty role list is not permission, and an unauthenticated actor is
 * never authorized. Absence of information refuses, never admits. This is
 * the same discipline as the `FinanceReconciliationAuthorizer` family — a
 * bare customer (authenticated, but holding no back-office role) must NEVER
 * pass.
 */
interface MasterDataAdminAuthorizerContract
{
    /**
     * Authorize the server-resolved actor for master-data administration.
     *
     * @throws MasterDataNotAuthorisedException
     */
    public function authorize(ActorContext $actor): void;
}
