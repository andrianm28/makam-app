<?php

declare(strict_types=1);

namespace Tests\Feature\IdentityAccess;

use App\Platform\IdentityAccess\ActorContextResolver;
use App\Platform\IdentityAccess\Adapters\LocalUsersTableIdentityAccessAdapter;
use App\Platform\IdentityAccess\Contracts\IdentityAccessAdapter;
use Tests\TestCase;

/**
 * Locks in the interface-vs-implementation seam: consumers depend on
 * `IdentityAccessAdapter` (the contract design.md names), and the
 * container resolves it to `LocalUsersTableIdentityAccessAdapter` (this
 * batch's MVP/local-auth implementation) — not the other way around, and
 * not a consumer newing up the concrete class directly.
 */
final class IdentityAccessServiceProviderBindingTest extends TestCase
{
    public function test_identity_access_adapter_interface_resolves_to_the_local_mvp_implementation(): void
    {
        $this->assertInstanceOf(
            LocalUsersTableIdentityAccessAdapter::class,
            $this->app->make(IdentityAccessAdapter::class)
        );
    }

    public function test_actor_context_resolver_is_resolvable_from_the_container(): void
    {
        $this->assertInstanceOf(
            ActorContextResolver::class,
            $this->app->make(ActorContextResolver::class)
        );
    }
}
