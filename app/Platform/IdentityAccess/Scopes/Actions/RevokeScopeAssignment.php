<?php

declare(strict_types=1);

namespace App\Platform\IdentityAccess\Scopes\Actions;

use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditOutcome;
use App\Platform\Audit\AuditSource;
use App\Platform\Audit\AuditSubject;
use App\Platform\IdentityAccess\Roles\ActorRole;
use App\Platform\IdentityAccess\Scopes\Models\ScopeAssignment;
use App\Platform\IdentityAccess\Scopes\ScopeAuditActions;
use App\Platform\IdentityAccess\Scopes\ScopeEntityType;

/**
 * The only write path that soft-revokes `scope_assignments` rows —
 * structural twin of `Roles\Actions\RevokeActorRole`; see that class's own
 * doc block for the shared background (soft-revoke only, potentially more
 * than one matching row, subject identifies the grant triple rather than a
 * single row id because it is known before the mutation runs).
 *
 * `ScopeEntityType::assertKnown()` runs before `Audit::wrap()` opens its
 * transaction. There is no grant-level parameter here — revocation targets
 * an actor/entity_type/entity_id grant regardless of what level (if any) it
 * was granted at, matching `GrantScopeAssignment`'s and
 * `ScopeAssignmentReader`'s own entity-keyed (not level-keyed) shape.
 */
final readonly class RevokeScopeAssignment
{
    public function __invoke(
        int|string $actorIdentifier,
        string $entityType,
        int|string $entityId,
        string $reason,
        int|string|null $revokedBy,
    ): int {
        ScopeEntityType::assertKnown($entityType);

        return Audit::wrap(
            mutation: function () use ($actorIdentifier, $entityType, $entityId): int {
                $assignments = ScopeAssignment::query()
                    ->where('actor_identifier', (string) $actorIdentifier)
                    ->where('entity_type', $entityType)
                    ->where('entity_id', (string) $entityId)
                    ->whereNull('revoked_at')
                    ->get();

                $assignments->each(fn (ScopeAssignment $assignment) => $assignment->revoke());

                return $assignments->count();
            },
            action: ScopeAuditActions::REVOKE,
            subject: new AuditSubject('scope_assignment', "{$actorIdentifier}:{$entityType}:{$entityId}"),
            outcome: AuditOutcome::Allowed,
            actorRef: $revokedBy,
            actorRole: ActorRole::SYSTEM,
            source: AuditSource::Console,
            reason: $reason,
            // 'new_state' carries the revoked "entity_type:entity_id" pair —
            // see `MetadataAllowlist::ALLOWED_KEYS`'s doc block for why no
            // new key was added for this.
            metadata: ['new_state' => "{$entityType}:{$entityId}"],
        );
    }
}
