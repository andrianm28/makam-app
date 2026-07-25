<?php

declare(strict_types=1);

namespace App\Platform\IdentityAccess\Scopes;

use App\Platform\IdentityAccess\ActorContext;
use App\Platform\IdentityAccess\Scopes\Models\ScopeAssignment;

/**
 * Reads `scope_assignments` for the current actor. Two distinct jobs, kept
 * on one class because both start from the same "who is acting" question:
 *
 * 1. `grantedEntityIds()` — the query-scope mechanism itself.
 *    `ScopeAssignmentGlobalScope` calls this directly to build its
 *    `whereIn(...)` constraint.
 * 2. `scopeStringsForActor()` — produces `"entity_type:entity_id"` strings
 *    in exactly the shape `ActorContext::$scopes`/`hasScope()` expect (see
 *    `ActorContextTest::test_scopes_placeholder_never_reports_a_scope_...`
 *    for the expected `"cemetery:1"` format this batch matched
 *    deliberately). This method exists so that whoever wires real data into
 *    `ActorContext::$scopes` has a ready-made source to call — see this
 *    class's own doc block continuation below for why this batch does not
 *    do that wiring itself.
 *
 * ---------------------------------------------------------------------------
 * The `ActorContext::$scopes` wiring gap — read before assuming this is done
 * ---------------------------------------------------------------------------
 * `ActorContext::$scopes` is populated ONLY by whatever constructs
 * `ActorContext` — today, that is exclusively
 * `App\Platform\IdentityAccess\Adapters\LocalUsersTableIdentityAccessAdapter
 * ::resolveActorContext()`, a file this batch is explicitly not allowed to
 * touch (owned by the already-merged Batch 3.1, outside
 * `IdentityAccess/Scopes/`). This class is the service that adapter would
 * call — e.g. `scopes: $resolver->scopeStringsForActor($user->id)` — to
 * make `ActorContext::$scopes` real instead of always `[]`. That one-line
 * integration is a small, explicit follow-up for whoever next touches that
 * adapter; this batch cannot make the change itself without violating its
 * file-ownership boundary. Flagged in this batch's report as well.
 *
 * Neither method above depends on `ActorContext::$scopes` being populated —
 * `ScopeAssignmentGlobalScope` (the actual enforcement mechanism this batch
 * is responsible for shipping working today) reads `scope_assignments`
 * directly via `grantedEntityIds()`, keyed off `ActorContext
 * ::$identityReference` only. It works correctly right now, independent of
 * whether the `$scopes` wiring above ever lands.
 */
final class ScopeAssignmentResolver
{
    public function __construct(
        private readonly ActorContext $actorContext,
    ) {}

    /**
     * The identity reference `scope_assignments.actor_identifier` rows are
     * keyed on for the current actor, or `null` for a guest/unauthenticated
     * request.
     */
    public function currentActorIdentifier(): int|string|null
    {
        return $this->actorContext->identityReference;
    }

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
     * All of the given actor's active grants, formatted as
     * `"entity_type:entity_id"` strings — the shape `ActorContext::$scopes`
     * and `ActorContext::hasScope()` expect. See this class's own doc block
     * for why this is not wired into `ActorContext` construction by this
     * batch.
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
