<?php

declare(strict_types=1);

namespace App\Platform\IdentityAccess\Roles;

use App\Platform\IdentityAccess\Roles\Models\ActorRoleAssignment;

/**
 * Stateless, actor-keyed reads over `actor_role_assignments`. Structural
 * twin of `Scopes\ScopeAssignmentReader` — see that class's doc block for
 * the full story on why this shape exists.
 *
 * **No constructor dependencies, and nothing in this class's graph may
 * depend on `ActorContext`.** `LocalUsersTableIdentityAccessAdapter`
 * constructor-injects this reader to build the very `ActorContext` that
 * flows through `ActorContextResolver`; if this class (or anything it
 * depends on) took an `ActorContext`, that would close a container cycle.
 * Verified empirically to not raise `CircularDependencyException` but
 * instead recurse unboundedly, climbing to ~1GB RSS until the host OOMs —
 * see the design doc, decision 4.
 */
final class ActorRoleReader
{
    /**
     * Every role the given actor currently holds via an active (non-
     * revoked) `actor_role_assignments` row, de-duplicated and ordered by
     * `ActorRole::KNOWN_ROLES` declaration order — NOT database insertion
     * order. `DocumentAccessPolicy::auditRoleFor()` walks a role list to
     * pick a single most-privileged match for an audit row; a
     * non-deterministic order there would make that pick non-deterministic
     * too, so this reader sorts against the one declared precedence order
     * rather than trusting whatever order the database happens to return.
     *
     * An empty list means "this actor has no roles" — it must NEVER be
     * read by a caller as "no roles required." Every one of the five
     * previously-inert authorizers fails closed on an empty list; that is
     * the correct, deliberate behaviour for an actor with no grants.
     *
     * @return list<string>
     */
    public function rolesForActor(int|string $actorIdentifier): array
    {
        $roles = ActorRoleAssignment::query()
            ->where('actor_identifier', (string) $actorIdentifier)
            ->whereNull('revoked_at')
            ->pluck('role')
            ->unique()
            ->all();

        usort(
            $roles,
            static fn (string $a, string $b): int => array_search($a, ActorRole::KNOWN_ROLES, true)
                <=> array_search($b, ActorRole::KNOWN_ROLES, true)
        );

        return array_values($roles);
    }
}
