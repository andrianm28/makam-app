<?php

declare(strict_types=1);

namespace Tests\Feature\IdentityAccess;

use App\Models\User;
use App\Platform\IdentityAccess\ActorContext;
use App\Platform\IdentityAccess\ActorContextResolver;
use App\Platform\IdentityAccess\Contracts\IdentityAccessAdapter;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * AC8: "resolve actor context ... once per request as the single source
 * consumers read." This is the class actually responsible for the "once"
 * part (see its own doc block for how the container binding avoids leaking
 * across requests/jobs — that half is not exercised here, since a single
 * PHPUnit test method already gets a fresh container per test the same way
 * a fresh request would in production without Octane; what IS exercised
 * here is that resolve() itself only calls the adapter once no matter how
 * many times it is called within that one instance's lifetime).
 */
final class ActorContextResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_resolve_only_calls_the_adapter_once_across_multiple_calls(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $adapter = Mockery::mock(IdentityAccessAdapter::class);
        $adapter->shouldReceive('resolveActorContext')
            ->once()
            ->andReturn(new ActorContext(identityReference: $user->id));

        $resolver = new ActorContextResolver($adapter, $this->app->make(AuthFactory::class));

        $first = $resolver->resolve();
        $second = $resolver->resolve();
        $third = $resolver->resolve();

        $this->assertSame($first, $second);
        $this->assertSame($second, $third);
    }

    public function test_forget_forces_re_resolution_on_the_next_call(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $adapter = Mockery::mock(IdentityAccessAdapter::class);
        $adapter->shouldReceive('resolveActorContext')
            ->twice()
            ->andReturn(new ActorContext(identityReference: $user->id));

        $resolver = new ActorContextResolver($adapter, $this->app->make(AuthFactory::class));

        $resolver->resolve();
        $resolver->forget();
        $resolver->resolve();
    }

    public function test_unauthenticated_request_resolves_a_guest_actor_context_via_the_container_binding(): void
    {
        // No actingAs() — exercises the real bound IdentityAccessAdapter
        // (LocalUsersTableIdentityAccessAdapter) through the container, not
        // a mock, to prove the end-to-end wiring in
        // IdentityAccessServiceProvider resolves to a sane guest state.
        $context = $this->app->make(ActorContext::class);

        $this->assertFalse($context->isAuthenticated());
    }

    public function test_authenticated_request_resolves_actor_context_with_the_users_id_via_the_container_binding(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $context = $this->app->make(ActorContext::class);

        $this->assertTrue($context->isAuthenticated());
        $this->assertSame($user->id, $context->identityReference);
    }

    public function test_actor_context_binding_returns_the_same_instance_within_one_container_lifetime(): void
    {
        // The convenience binding (ActorContext::class itself) must not
        // re-resolve on a second app()->make() call within the same
        // request/container — that would defeat "single source consumers
        // read" the moment two different consumers both type-hint
        // ActorContext directly.
        $user = User::factory()->create();
        $this->actingAs($user);

        $first = $this->app->make(ActorContext::class);
        $second = $this->app->make(ActorContext::class);

        $this->assertSame($first, $second);
    }
}
