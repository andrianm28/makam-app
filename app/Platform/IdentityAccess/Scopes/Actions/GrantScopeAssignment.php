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
use App\Platform\IdentityAccess\Scopes\ScopeGrantLevel;

/**
 * The only write path that creates a `scope_assignments` row —
 * `platform-identity-seam` design doc decision 5. Structural twin of
 * `Roles\Actions\GrantActorRole`; see that class's own doc block for the
 * shared background (console-only, validate-before-transaction,
 * `ActorRole::SYSTEM` reasoning). This class covers only what differs.
 *
 * Both closed lists are validated before `Audit::wrap()` opens its
 * transaction: `ScopeEntityType::assertKnown()` always, and
 * `ScopeGrantLevel::assertKnown()` only when `$grantLevel` is non-null,
 * matching `ScopeAssignment`'s own `saving` listener.
 */
final readonly class GrantScopeAssignment
{
    public function __invoke(
        int|string $actorIdentifier,
        string $entityType,
        int|string $entityId,
        ?string $grantLevel,
        string $reason,
        int|string|null $grantedBy,
    ): ScopeAssignment {
        ScopeEntityType::assertKnown($entityType);

        if ($grantLevel !== null) {
            ScopeGrantLevel::assertKnown($grantLevel);
        }

        return Audit::wrap(
            mutation: fn (): ScopeAssignment => ScopeAssignment::create([
                'actor_identifier' => (string) $actorIdentifier,
                'entity_type' => $entityType,
                'entity_id' => (string) $entityId,
                'grant_level' => $grantLevel,
            ]),
            action: ScopeAuditActions::GRANT,
            subject: fn (ScopeAssignment $assignment): AuditSubject => new AuditSubject('scope_assignment', $assignment->id),
            outcome: AuditOutcome::Allowed,
            actorRef: $grantedBy,
            actorRole: ActorRole::SYSTEM,
            source: AuditSource::Console,
            reason: $reason,
            // 'new_state' carries the granted "entity_type:entity_id" pair —
            // see `MetadataAllowlist::ALLOWED_KEYS`'s doc block for why no
            // new key was added for this.
            metadata: ['new_state' => "{$entityType}:{$entityId}"],
        );
    }
}
