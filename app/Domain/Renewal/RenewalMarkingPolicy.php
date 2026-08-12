<?php

declare(strict_types=1);

namespace App\Domain\Renewal;

use App\Domain\GraveRegistry\Models\GraveRecord;
use App\Platform\IdentityAccess\ActorContext;
use App\Platform\IdentityAccess\Roles\ActorRole;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Policy for the AC10 privileged marking action — "admin/operator SHALL be
 * able to mark a renewal as paid externally, with evidence."
 *
 * Per Ruling B (explicit human sign-off, 12 Aug 2026): **`admin` only.**
 * `operator` is explicitly denied, and a test must prove the denial. The
 * authorizer requires a role AND a scope grant — scoped by cemetery.
 *
 * The scope check uses the cemetery of the grave being renewed: the actor
 * must have an active `scope_assignments` row for `cemetery:{cemetery_id}`.
 * This matches the existing scope pattern (`ScopeAssignmentGlobalScope`,
 * `DocumentAccessPolicy`) and is the minimum necessary scope for a marking
 * action that affects a specific grave.
 */
final class RenewalMarkingPolicy
{
    /**
     * @throws AuthorizationException when the actor is not authorized.
     */
    public function allows(ActorContext $actor, GraveRecord $grave): void
    {
        if (! $actor->hasRole(ActorRole::ADMIN)) {
            throw new AuthorizationException('Only an admin may mark an external renewal.');
        }

        $cemeteryScope = 'cemetery:'.$grave->cemetery_id;

        if (! $actor->hasScope($cemeteryScope)) {
            throw new AuthorizationException('Admin must have a scope grant for this cemetery.');
        }
    }
}
