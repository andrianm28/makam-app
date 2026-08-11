<?php

declare(strict_types=1);

namespace Tests\Feature\IdentityAccess\Roles;

use App\Platform\IdentityAccess\Roles\ActorRole;
use App\Platform\IdentityAccess\Roles\Models\ActorRoleAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * `ActorRoleAssignment` is the row `Roles\ActorRoleReader` (Task 3) reads to
 * resolve `ActorContext::$roles`. `role` is a plain string column (not a
 * Postgres enum, per the migration's doc block) validated at the
 * application layer on every save — this locks that validation in.
 */
final class ActorRoleAssignmentModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_row_with_a_known_role_saves(): void
    {
        $assignment = ActorRoleAssignment::create([
            'actor_identifier' => '1',
            'role' => ActorRole::FINANCE,
        ]);

        $this->assertDatabaseHas('actor_role_assignments', [
            'id' => $assignment->id,
            'actor_identifier' => '1',
            'role' => 'finance',
            'revoked_at' => null,
        ]);
    }

    public function test_it_rejects_a_role_outside_the_closed_list_on_save(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ActorRoleAssignment::create(['actor_identifier' => '1', 'role' => 'wizard']);
    }

    public function test_it_rejects_the_guest_sentinel_on_save(): void
    {
        // guest/authenticated_actor must never reach the table — see
        // ActorRole's class-level doc block.
        $this->expectException(InvalidArgumentException::class);
        ActorRoleAssignment::create(['actor_identifier' => '1', 'role' => 'guest']);
    }

    public function test_a_fresh_assignment_is_active(): void
    {
        $assignment = ActorRoleAssignment::create([
            'actor_identifier' => '1',
            'role' => ActorRole::ADMIN,
        ]);

        $this->assertTrue($assignment->isActive());
        $this->assertNull($assignment->revoked_at);
    }

    public function test_revoke_soft_revokes_without_deleting_the_row(): void
    {
        $assignment = ActorRoleAssignment::create(['actor_identifier' => '1', 'role' => ActorRole::FINANCE]);
        $assignment->revoke();

        $this->assertFalse($assignment->fresh()->isActive());
        $this->assertDatabaseCount('actor_role_assignments', 1);
    }
}
