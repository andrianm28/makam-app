<?php

declare(strict_types=1);

namespace App\Platform\IdentityAccess\Panel;

use App\Platform\IdentityAccess\ActorContext;
use App\Platform\IdentityAccess\Contracts\PanelAccessPolicy;
use App\Platform\IdentityAccess\Roles\ActorRole;
use App\Platform\IdentityAccess\Scopes\ScopeEntityType;

/**
 * Explicit access check for the `/operator` Filament panel — AC4: "THE
 * SYSTEM SHALL require each panel (`/admin`, `/vendor`, operator) to declare
 * explicit access checks. THE SYSTEM SHALL NOT grant record access on panel
 * membership alone."
 *
 * `docs/superpowers/plans/2026-08-28-operator-panel-and-role.md` (Task 2),
 * implementing the TPU/TPS operator dashboard roadmap's "Role & scoping"
 * section. Deliberately structured identically to `VendorPanelAccessPolicy`
 * — see that class's own doc block for the full reasoning on why BOTH the
 * role and an active scope grant are required (neither substitutes for the
 * other), and why refusing at the panel boundary when the grant list is
 * empty is more honest than admitting the actor to a panel of uniformly
 * empty tables.
 *
 * Panel entry is still not record access. `Domain\CemeteryDirectory\Access
 * \CurrentCemeteryScope` (this same plan's Task 3) is what decides which
 * cemetery's rows an admitted actor actually sees, applied per query by
 * every Resource and Page in the panel.
 *
 * Widening either condition is an authorization change and carries
 * `AGENTS.md` §Infrastructure-agent execution's mandatory-human-review bar.
 */
final class CemeteryOperatorPanelAccessPolicy implements PanelAccessPolicy
{
    private const string CEMETERY_SCOPE_PREFIX = ScopeEntityType::CEMETERY.':';

    public function allows(ActorContext $actor): bool
    {
        if (! $actor->isAuthenticated()) {
            return false;
        }

        if (! $actor->hasRole(ActorRole::CEMETERY_OPERATOR)) {
            return false;
        }

        foreach ($actor->scopes as $scope) {
            if (str_starts_with($scope, self::CEMETERY_SCOPE_PREFIX)) {
                return true;
            }
        }

        return false;
    }
}
