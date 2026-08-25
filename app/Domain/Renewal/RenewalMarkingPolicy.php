<?php

declare(strict_types=1);

namespace App\Domain\Renewal;

use App\Domain\GraveRegistry\Models\GraveRecord;
use App\Platform\IdentityAccess\ActorContext;
use App\Platform\IdentityAccess\Roles\ActorRole;
use App\Platform\IdentityAccess\Scopes\ScopeAssignmentReader;
use App\Platform\IdentityAccess\Scopes\ScopeEntityType;
use App\Platform\IdentityAccess\Scopes\ScopeGrantLevel;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Policy for the AC10 privileged marking action — "admin/operator SHALL be
 * able to mark a renewal as paid externally, with evidence."
 *
 * Per Ruling B (explicit human sign-off, 12 Aug 2026): **`admin` only.**
 * `operator` is explicitly denied, and a test proves the denial with a real
 * privileged cemetery grant in place, so that only the role check can be what
 * refuses it.
 *
 * ---------------------------------------------------------------------------
 * Three checks, each independently capable of denying
 * ---------------------------------------------------------------------------
 * 1. The actor is authenticated. `ActorContext::guest()` carries no identity
 *    to scope-check against, so it is refused before anything else runs.
 * 2. The actor holds `ActorRole::ADMIN`.
 * 3. The actor holds a `ScopeGrantLevel::PRIVILEGED` grant on the grave's
 *    cemetery.
 *
 * Check 3 reads the grant LEVEL, not merely the grant's existence. The matrix's
 * own closing paragraph requires a role AND a scope grant, but a bare
 * `hasScope('cemetery:{id}')` is satisfied by any grant on that cemetery —
 * including a `ScopeGrantLevel::READ` grant issued to an auditor for read-only
 * visibility. Letting a read grant authorize a money-attestation write would
 * silently promote every auditor to an admin capability. `ScopeGrantLevel`'s
 * own doc block reserves exactly this decision for the Policy layer, which is
 * this class.
 *
 * `allows()` RETURNS the role it matched rather than returning void, so the
 * caller's audit record names the authority that actually permitted the write
 * instead of restating a constant. If Ruling B later widens to more roles, the
 * audit trail follows automatically.
 */
final readonly class RenewalMarkingPolicy
{
    /**
     * Roles permitted to mark a renewal paid externally, in precedence order.
     * Ruling B admits exactly one today; this list is the seam a widening
     * ruling would edit, and the audit trail records whichever entry matched.
     *
     * @var list<string>
     */
    private const array PERMITTED_ROLES = [
        ActorRole::ADMIN,
    ];

    public function __construct(
        private ScopeAssignmentReader $scopes,
    ) {}

    /**
     * @return string the role that authorized this action — one of
     *                `self::PERMITTED_ROLES`
     *
     * @throws AuthorizationException when the actor is not authorized.
     */
    public function allows(ActorContext $actor, GraveRecord $grave): string
    {
        if (! $actor->isAuthenticated() || $actor->identityReference === null) {
            throw new AuthorizationException('An unauthenticated actor may not mark an external renewal.');
        }

        $matchedRole = null;

        foreach (self::PERMITTED_ROLES as $role) {
            if ($actor->hasRole($role)) {
                $matchedRole = $role;

                break;
            }
        }

        if ($matchedRole === null) {
            throw new AuthorizationException('Only an admin may mark an external renewal.');
        }

        $hasPrivilegedCemeteryGrant = $this->scopes->hasGrantAtLevel(
            $actor->identityReference,
            ScopeEntityType::CEMETERY,
            (string) $grave->cemetery_id,
            ScopeGrantLevel::PRIVILEGED,
        );

        if (! $hasPrivilegedCemeteryGrant) {
            throw new AuthorizationException(
                'Marking an external renewal requires a privileged scope grant for this cemetery.'
            );
        }

        return $matchedRole;
    }
}
