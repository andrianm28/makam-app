<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\User;
use App\Platform\IdentityAccess\ActorContext;
use App\Platform\IdentityAccess\ActorContextResolver;
use App\Platform\IdentityAccess\Roles\Actions\GrantActorRole;
use App\Platform\IdentityAccess\Roles\Actions\RevokeActorRole;

/**
 * Test-side helper for putting a real role on a real actor.
 *
 * Goes through `Roles\Actions\GrantActorRole` — the ONLY write path to
 * `actor_role_assignments` (that Action's own doc block, design decision 5) —
 * rather than inserting a row directly or hand-constructing an `ActorContext`
 * with a `roles` array. A test that fabricates its own context proves the
 * authorizer's `in_array()` works; a test that grants for real proves the
 * whole chain works: grant row -> `Roles\ActorRoleReader` ->
 * `Adapters\LocalUsersTableIdentityAccessAdapter` -> `ActorContext::$roles` ->
 * the authorizer. Only the second kind can fail when that chain breaks.
 *
 * `$reason` is mandatory on `GrantActorRole` because `RoleAuditActions::GRANT`
 * is on `Audit\SensitiveActions`' closed list, so a blank one throws
 * `AuditReasonRequiredException` — the default below is a real sentence for
 * that reason, not decoration.
 *
 * ---------------------------------------------------------------------------
 * Why the two `forgetInstance()` calls are load-bearing
 * ---------------------------------------------------------------------------
 * `ActorContext` and `ActorContextResolver` are `scoped()` bindings
 * (`IdentityAccessServiceProvider`): the container resolves each at most once
 * per request/job and hands back the cached instance thereafter. A test runs
 * many logical "requests" inside one container, so anything that resolved a
 * context BEFORE the grant — `Livewire::test()`, an HTTP call, an eager
 * consumer — would pin a roles-less snapshot that this grant then silently
 * fails to change, and the test would report a false denial. Dropping both
 * instances forces the next resolution to re-read `actor_role_assignments`.
 *
 * Deliberately both, not just `ActorContext`: the resolver caches its own
 * result in a private `$resolved` property, so a surviving resolver instance
 * would re-serve the stale context to the freshly-bound `ActorContext`
 * closure.
 */
trait GrantsActorRoles
{
    private function grantRoleTo(User $user, string $role, string $reason = 'Test fixture: exercising the authorization boundary.'): void
    {
        app(GrantActorRole::class)(
            actorIdentifier: $user->id,
            role: $role,
            reason: $reason,
            grantedBy: null,
        );

        $this->forgetResolvedActorContext();
    }

    /**
     * The mirror of `grantRoleTo()`, through the only revoke path
     * (`Roles\Actions\RevokeActorRole`). Lets a test change an actor's
     * authority mid-test without rebuilding the container.
     */
    private function revokeRoleFrom(User $user, string $role, string $reason = 'Test fixture: withdrawing the authority under test.'): void
    {
        app(RevokeActorRole::class)(
            actorIdentifier: $user->id,
            role: $role,
            reason: $reason,
            revokedBy: null,
        );

        $this->forgetResolvedActorContext();
    }

    private function forgetResolvedActorContext(): void
    {
        $this->app->forgetInstance(ActorContext::class);
        $this->app->forgetInstance(ActorContextResolver::class);
    }
}
