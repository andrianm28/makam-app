<?php

declare(strict_types=1);

namespace App\Platform\IdentityAccess\Cemetery;

use App\Domain\CemeteryDirectory\Access\CurrentCemeteryScope;
use App\Platform\IdentityAccess\ActorContext;
use App\Platform\IdentityAccess\Roles\ActorRole;

/**
 * Resource-level access check for `App\Filament\Operator\Resources
 * \CemeteryOrders\CemeteryOrderResource` — the `/operator` panel's orders
 * surface.
 *
 * ---------------------------------------------------------------------------
 * Why this exists instead of reusing `MasterDataAdminAuthorizer`
 * ---------------------------------------------------------------------------
 * `BookingOrderResource` (the `/admin` twin) delegates to
 * `MasterDataAdminAuthorizerContract`, whose implementation admits a fixed
 * four-role list and — by its own doc block — performs NO record scoping,
 * because master data "is platform-wide: there is no record scope to
 * check". Adding `cemetery_operator` to that list would therefore make
 * every composed authorization check in the order actions answer "yes" for
 * orders belonging to every cemetery, with nothing downstream to narrow it:
 * `BookingOrderResource::getEloquentQuery()` is deliberately unscoped. That
 * is cross-tenant exposure, so the operator surface gets its own gate.
 *
 * ---------------------------------------------------------------------------
 * Two conditions, and why this is NOT a per-record check
 * ---------------------------------------------------------------------------
 * Role AND at least one active cemetery grant — the same pair, for the same
 * reasons, as `Panel\CemeteryOperatorPanelAccessPolicy`: neither condition
 * substitutes for the other, and refusing an actor with an empty grant list
 * is more honest than admitting them to a uniformly empty table.
 *
 * Which cemetery's rows they then see is a different question, answered per
 * query by `App\Filament\Operator\Concerns\ScopesToCurrentCemetery` — so a
 * direct URL to another cemetery's order 404s at record resolution rather
 * than being refused here. This gate is deliberately grant-level, not
 * record-level; merging the two would duplicate an enforcement that already
 * has one correct home, and the duplicate is the copy that drifts.
 *
 * Widening either condition is an authorization change and carries
 * `AGENTS.md` §Infrastructure-agent execution's mandatory-human-review bar.
 */
final class CemeteryOrderAccessPolicy
{
    public function __construct(
        private readonly CurrentCemeteryScope $cemeteries,
    ) {}

    public function allows(ActorContext $actor): bool
    {
        if (! $actor->isAuthenticated()) {
            return false;
        }

        if (! $actor->hasRole(ActorRole::CEMETERY_OPERATOR)) {
            return false;
        }

        return $this->cemeteries->hasAnyGrant();
    }
}
