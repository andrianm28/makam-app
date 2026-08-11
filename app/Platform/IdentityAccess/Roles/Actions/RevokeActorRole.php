<?php

declare(strict_types=1);

namespace App\Platform\IdentityAccess\Roles\Actions;

use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditOutcome;
use App\Platform\Audit\AuditSource;
use App\Platform\Audit\AuditSubject;
use App\Platform\IdentityAccess\Roles\ActorRole;
use App\Platform\IdentityAccess\Roles\Models\ActorRoleAssignment;
use App\Platform\IdentityAccess\Roles\RoleAuditActions;

/**
 * The only write path that soft-revokes `actor_role_assignments` rows —
 * see `Actions\GrantActorRole`'s doc block for the shared background
 * (console-only, validate-before-transaction, `ActorRole::SYSTEM`
 * reasoning). This class covers only what differs.
 *
 * ---------------------------------------------------------------------------
 * Soft-revoke only, and potentially more than one row
 * ---------------------------------------------------------------------------
 * Sets every matching active row's `revoked_at` — it never deletes a row
 * (`ActorRoleAssignment::revoke()`). An actor/role pair is not guaranteed
 * unique (no unique constraint on the table — see the migration's doc
 * block), so more than one active grant of the same role to the same actor
 * can exist; this revokes all of them and returns how many.
 *
 * The audit subject therefore cannot be a single row id the way a grant's
 * can — it identifies the actor/role pair being revoked instead, known
 * before the mutation runs, so unlike `GrantActorRole` this passes
 * `AuditSubject` directly rather than through the result closure.
 */
final readonly class RevokeActorRole
{
    public function __invoke(
        int|string $actorIdentifier,
        string $role,
        string $reason,
        int|string|null $revokedBy,
    ): int {
        ActorRole::assertKnown($role);

        return Audit::wrap(
            mutation: function () use ($actorIdentifier, $role): int {
                $assignments = ActorRoleAssignment::query()
                    ->where('actor_identifier', (string) $actorIdentifier)
                    ->where('role', $role)
                    ->whereNull('revoked_at')
                    ->get();

                $assignments->each(fn (ActorRoleAssignment $assignment) => $assignment->revoke());

                return $assignments->count();
            },
            action: RoleAuditActions::REVOKE,
            subject: new AuditSubject('actor_role_assignment', "{$actorIdentifier}:{$role}"),
            outcome: AuditOutcome::Allowed,
            actorRef: $revokedBy,
            actorRole: ActorRole::SYSTEM,
            source: AuditSource::Console,
            reason: $reason,
            // 'new_state' carries the revoked role — see
            // `MetadataAllowlist::ALLOWED_KEYS`'s doc block for why no new
            // key was added for this.
            metadata: ['new_state' => $role],
        );
    }
}
