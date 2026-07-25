<?php

declare(strict_types=1);

namespace Tests\Feature\IdentityAccess;

use App\Models\User;
use App\Platform\IdentityAccess\ActorContext;
use App\Platform\IdentityAccess\Adapters\LocalUsersTableIdentityAccessAdapter;
use App\Platform\IdentityAccess\Models\ActorSession;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The MVP `IdentityAccessAdapter` implementation, backed by the `users` and
 * `actor_sessions` tables. Explicitly does NOT test anything about a real
 * K1/K2 contract — there isn't one to test against (see the class's own
 * doc block).
 */
final class LocalUsersTableIdentityAccessAdapterTest extends TestCase
{
    use RefreshDatabase;

    public function test_null_identity_resolves_to_guest_context(): void
    {
        $adapter = new LocalUsersTableIdentityAccessAdapter;

        $context = $adapter->resolveActorContext(null);

        $this->assertFalse($context->isAuthenticated());
        $this->assertSame(ActorContext::MFA_STATE_NOT_APPLICABLE, $context->mfaState);
    }

    public function test_authenticated_identity_resolves_with_identity_reference_set(): void
    {
        $user = User::factory()->create();
        $adapter = new LocalUsersTableIdentityAccessAdapter;

        $context = $adapter->resolveActorContext($user);

        $this->assertTrue($context->isAuthenticated());
        $this->assertSame($user->id, $context->identityReference);
    }

    public function test_authenticated_identity_with_no_actor_session_has_null_last_authenticated_at(): void
    {
        // The known, flagged gap: nothing has written an actor_sessions row
        // for this user (no login flow has run), so lastAuthenticatedAt
        // must be null rather than fabricated.
        $user = User::factory()->create();
        $adapter = new LocalUsersTableIdentityAccessAdapter;

        $context = $adapter->resolveActorContext($user);

        $this->assertNull($context->lastAuthenticatedAt);
    }

    public function test_last_authenticated_at_reflects_the_most_recent_non_revoked_actor_session(): void
    {
        $user = User::factory()->create();

        ActorSession::query()->create([
            'user_id' => $user->id,
            'session_id' => 'older-session',
            'guard' => 'web',
            'last_authenticated_at' => CarbonImmutable::parse('2026-07-20T09:00:00Z'),
        ]);
        ActorSession::query()->create([
            'user_id' => $user->id,
            'session_id' => 'newer-session',
            'guard' => 'web',
            'last_authenticated_at' => CarbonImmutable::parse('2026-07-24T09:00:00Z'),
        ]);

        $adapter = new LocalUsersTableIdentityAccessAdapter;
        $context = $adapter->resolveActorContext($user);

        $this->assertNotNull($context->lastAuthenticatedAt);
        $this->assertTrue(
            CarbonImmutable::parse('2026-07-24T09:00:00Z')->equalTo($context->lastAuthenticatedAt)
        );
    }

    public function test_revoked_actor_sessions_are_excluded_from_last_authenticated_at(): void
    {
        $user = User::factory()->create();

        ActorSession::query()->create([
            'user_id' => $user->id,
            'session_id' => 'revoked-session',
            'guard' => 'web',
            'last_authenticated_at' => CarbonImmutable::parse('2026-07-25T09:00:00Z'),
            'revoked_at' => CarbonImmutable::parse('2026-07-25T09:05:00Z'),
        ]);
        ActorSession::query()->create([
            'user_id' => $user->id,
            'session_id' => 'still-active-session',
            'guard' => 'web',
            'last_authenticated_at' => CarbonImmutable::parse('2026-07-23T09:00:00Z'),
        ]);

        $adapter = new LocalUsersTableIdentityAccessAdapter;
        $context = $adapter->resolveActorContext($user);

        $this->assertTrue(
            CarbonImmutable::parse('2026-07-23T09:00:00Z')->equalTo($context->lastAuthenticatedAt)
        );
    }

    public function test_authenticated_identity_always_has_empty_roles_and_scopes_today(): void
    {
        // Locks in the flagged gap documented on ActorContext/this adapter
        // — must not silently start returning fabricated roles/scopes.
        $user = User::factory()->create();
        $adapter = new LocalUsersTableIdentityAccessAdapter;

        $context = $adapter->resolveActorContext($user);

        $this->assertSame([], $context->roles);
        $this->assertSame([], $context->scopes);
    }

    public function test_authenticated_identity_mfa_state_is_not_implemented_not_satisfied(): void
    {
        $user = User::factory()->create();
        $adapter = new LocalUsersTableIdentityAccessAdapter;

        $context = $adapter->resolveActorContext($user);

        $this->assertSame(ActorContext::MFA_STATE_NOT_IMPLEMENTED, $context->mfaState);
    }
}
