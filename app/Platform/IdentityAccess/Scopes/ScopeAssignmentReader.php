<?php

declare(strict_types=1);

namespace App\Platform\IdentityAccess\Scopes;

use App\Platform\IdentityAccess\Scopes\Models\ScopeAssignment;

/**
 * Stateless, actor-keyed reads over `scope_assignments`. Extracted from
 * `ScopeAssignmentResolver` (see that class's doc block for the full
 * story) so the identity adapter has a `scope_assignments` reader it can
 * depend on without also depending on `ActorContext`.
 *
 * **No constructor dependencies, and nothing in this class's graph may
 * depend on `ActorContext`.** That is the entire reason this class exists
 * rather than the adapter calling `ScopeAssignmentResolver` directly:
 * `ScopeAssignmentResolver::__construct` takes an `ActorContext`, which is
 * itself resolved through the identity adapter. Injecting the resolver
 * into the adapter would close that container cycle — verified empirically
 * to not raise `CircularDependencyException` but instead recurse
 * unboundedly, climbing to ~1GB RSS until the host OOMs (see the design
 * doc, decision 4).
 *
 * `ScopeAssignmentResolver` delegates its three actor-keyed methods to an
 * instance of this class, so the query logic and the
 * `"entity_type:entity_id"` scope-string format exist in exactly one
 * place. `currentActorIdentifier()` stays on the resolver — it reads
 * `ActorContext`, which this class must never touch.
 */
final class ScopeAssignmentReader
{
    /**
     * Entity ids of `$entityType` the given actor has an active (non-
     * revoked) grant for. An empty list is the correct, deliberate result
     * for "no grants" — see `ScopeAssignmentGlobalScope`'s doc block for why
     * that closes the query rather than leaving it unconstrained.
     *
     * @return list<string>
     *
     * @throws \InvalidArgumentException when `$entityType` is not one of
     *                                   `ScopeEntityType::KNOWN_TYPES`.
     */
    public function grantedEntityIds(int|string $actorIdentifier, string $entityType): array
    {
        ScopeEntityType::assertKnown($entityType);

        return ScopeAssignment::query()
            ->where('actor_identifier', (string) $actorIdentifier)
            ->where('entity_type', $entityType)
            ->whereNull('revoked_at')
            ->pluck('entity_id')
            ->all();
    }

    /**
     * The inverse of `grantedEntityIds()` — every actor holding an active
     * (non-revoked) grant on `$entityType`/`$entityId`, deduplicated.
     *
     * Added by the L2 `platform-notifications` lane (branch
     * `lane/l2-notifications`) for recipient resolution — ruling 3 of
     * `docs/superpowers/plans/2026-08-10-wave1a-notifications-decisions.md`.
     * Recipient resolution (AC6: "resolve recipient scope from record
     * scope") needs to go from a record's scope entity (e.g. a cemetery) to
     * the actors who may act on it, which none of the actor-first methods
     * provide. The caller is `App\Platform\Notification\RecipientResolver`,
     * not anything in this module.
     *
     * @return list<string>
     *
     * @throws \InvalidArgumentException when `$entityType` is not one of
     *                                   `ScopeEntityType::KNOWN_TYPES`.
     */
    public function actorsForEntity(string $entityType, int|string $entityId): array
    {
        ScopeEntityType::assertKnown($entityType);

        return ScopeAssignment::query()
            ->where('entity_type', $entityType)
            ->where('entity_id', (string) $entityId)
            ->whereNull('revoked_at')
            ->distinct()
            ->pluck('actor_identifier')
            ->all();
    }

    /**
     * All of the given actor's active grants, formatted as
     * `"entity_type:entity_id"` strings — the shape `ActorContext::$scopes`
     * and `ActorContext::hasScope()` expect.
     *
     * @return list<string>
     */
    public function scopeStringsForActor(int|string $actorIdentifier): array
    {
        return ScopeAssignment::query()
            ->where('actor_identifier', (string) $actorIdentifier)
            ->whereNull('revoked_at')
            ->get(['entity_type', 'entity_id'])
            ->map(static fn (ScopeAssignment $assignment): string => "{$assignment->entity_type}:{$assignment->entity_id}")
            ->all();
    }
}
