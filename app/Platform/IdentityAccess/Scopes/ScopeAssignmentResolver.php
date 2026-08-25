<?php

declare(strict_types=1);

namespace App\Platform\IdentityAccess\Scopes;

use App\Platform\IdentityAccess\ActorContext;

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
 *    deliberately).
 *
 * ---------------------------------------------------------------------------
 * The `ActorContext::$scopes` wiring — where it belongs, and why not here
 * ---------------------------------------------------------------------------
 * `ActorContext::$scopes` is the identity adapter's to populate
 * (`App\Platform\IdentityAccess\Adapters\LocalUsersTableIdentityAccessAdapter
 * ::resolveActorContext()`), reading through
 * `ScopeAssignmentReader::scopeStringsForActor()` — **not** this class.
 *
 * Check the adapter itself for whether that wiring has landed; this doc
 * block states where the wiring belongs, not what the adapter currently
 * does, so that it cannot rot into a false claim either way.
 *
 * The wiring cannot go through this resolver: its
 * constructor takes an `ActorContext`
 * (`App\Platform\IdentityAccess\ActorContext`), and `ActorContext` is
 * itself resolved through the identity adapter. If the adapter depended on
 * this resolver, the container would close a cycle — verified empirically
 * to not raise `CircularDependencyException` but instead recurse
 * unboundedly, climbing to ~1GB RSS until the host OOMs (see the design
 * doc `docs/superpowers/specs/2026-08-11-platform-identity-seam-design.md`,
 * decision 4).
 *
 * `ScopeAssignmentReader` exists for exactly this reason: it is the
 * stateless extraction of this class's three actor-keyed query methods,
 * with no constructor dependencies and nothing in its graph referencing
 * `ActorContext`. This class now delegates its three query methods to a
 * `ScopeAssignmentReader` instance, so the query logic and the
 * `"entity_type:entity_id"` scope-string format exist in exactly one
 * place. This class's public API is unchanged by that extraction — every
 * existing consumer (`ScopeAssignmentGlobalScope`,
 * `DocumentVault\Policies\DocumentAccessPolicy`,
 * `Notification\RecipientResolver`) keeps working exactly as before.
 *
 * `currentActorIdentifier()` stays here rather than moving to the reader:
 * it reads `$this->actorContext`, and the reader must never depend on
 * `ActorContext` for the reason above. `ScopeAssignmentGlobalScope` (the
 * actual query-level enforcement mechanism) reads `scope_assignments`
 * directly via `grantedEntityIds()`, keyed off `ActorContext
 * ::$identityReference` only — it works correctly independent of whether
 * `$scopes` is populated.
 */
final class ScopeAssignmentResolver
{
    public function __construct(
        private readonly ActorContext $actorContext,
        private readonly ScopeAssignmentReader $reader = new ScopeAssignmentReader,
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
     * revoked) grant for. Delegates to `ScopeAssignmentReader` — see this
     * class's doc block for why the query logic lives there.
     *
     * @return list<string>
     *
     * @throws \InvalidArgumentException when `$entityType` is not one of
     *                                   `ScopeEntityType::KNOWN_TYPES`.
     */
    public function grantedEntityIds(int|string $actorIdentifier, string $entityType): array
    {
        return $this->reader->grantedEntityIds($actorIdentifier, $entityType);
    }

    /**
     * The inverse of `grantedEntityIds()` — every actor holding an active
     * (non-revoked) grant on `$entityType`/`$entityId`, deduplicated.
     * Delegates to `ScopeAssignmentReader` — see this class's doc block for
     * why the query logic lives there.
     *
     * @return list<string>
     *
     * @throws \InvalidArgumentException when `$entityType` is not one of
     *                                   `ScopeEntityType::KNOWN_TYPES`.
     */
    public function actorsForEntity(string $entityType, int|string $entityId): array
    {
        return $this->reader->actorsForEntity($entityType, $entityId);
    }

    /**
     * All of the given actor's active grants, formatted as
     * `"entity_type:entity_id"` strings — the shape `ActorContext::$scopes`
     * and `ActorContext::hasScope()` expect. Delegates to
     * `ScopeAssignmentReader` — see this class's doc block for why the
     * query logic lives there.
     *
     * @return list<string>
     */
    public function scopeStringsForActor(int|string $actorIdentifier): array
    {
        return $this->reader->scopeStringsForActor($actorIdentifier);
    }
}
