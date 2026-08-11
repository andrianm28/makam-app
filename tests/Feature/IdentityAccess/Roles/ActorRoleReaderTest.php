<?php

declare(strict_types=1);

namespace Tests\Feature\IdentityAccess\Roles;

use App\Platform\IdentityAccess\Roles\ActorRole;
use App\Platform\IdentityAccess\Roles\ActorRoleReader;
use App\Platform\IdentityAccess\Roles\Models\ActorRoleAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `ActorRoleReader` — the stateless `actor_role_assignments` reader
 * `LocalUsersTableIdentityAccessAdapter` depends on. See the class's own
 * doc block for why it must never depend on `ActorContext`.
 */
final class ActorRoleReaderTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_active_roles_in_known_roles_declaration_order(): void
    {
        // Inserted in reverse precedence deliberately: the reader must not
        // return database insertion order. DocumentAccessPolicy::auditRoleFor()
        // documents why non-deterministic order is a real problem.
        ActorRoleAssignment::create(['actor_identifier' => '5', 'role' => ActorRole::CUSTOMER]);
        ActorRoleAssignment::create(['actor_identifier' => '5', 'role' => ActorRole::ADMIN]);

        $this->assertSame(
            [ActorRole::ADMIN, ActorRole::CUSTOMER],
            (new ActorRoleReader)->rolesForActor(5),
        );
    }

    public function test_revoked_roles_are_excluded(): void
    {
        $grant = ActorRoleAssignment::create(['actor_identifier' => '5', 'role' => ActorRole::FINANCE]);
        $grant->revoke();

        $this->assertSame([], (new ActorRoleReader)->rolesForActor(5));
    }

    public function test_it_deduplicates_repeated_grants_of_the_same_role(): void
    {
        ActorRoleAssignment::create(['actor_identifier' => '5', 'role' => ActorRole::FINANCE]);
        ActorRoleAssignment::create(['actor_identifier' => '5', 'role' => ActorRole::FINANCE]);

        $this->assertSame([ActorRole::FINANCE], (new ActorRoleReader)->rolesForActor(5));
    }

    public function test_an_actor_with_no_grants_gets_an_empty_list(): void
    {
        $this->assertSame([], (new ActorRoleReader)->rolesForActor(999));
    }

    public function test_another_actors_grants_do_not_leak_into_this_actors_result(): void
    {
        ActorRoleAssignment::create(['actor_identifier' => '5', 'role' => ActorRole::ADMIN]);

        $this->assertSame([], (new ActorRoleReader)->rolesForActor(6));
    }
}
