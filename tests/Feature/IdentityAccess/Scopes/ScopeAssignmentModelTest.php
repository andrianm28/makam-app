<?php

declare(strict_types=1);

namespace Tests\Feature\IdentityAccess\Scopes;

use App\Platform\IdentityAccess\Scopes\Models\ScopeAssignment;
use App\Platform\IdentityAccess\Scopes\ScopeEntityType;
use App\Platform\IdentityAccess\Scopes\ScopeGrantLevel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * `ScopeAssignment` is the row `ScopeAssignmentGlobalScope` reads.
 * `entity_type`/`grant_level` are plain string columns (not a Postgres
 * enum, per the migration's doc block) validated at the application layer
 * on every save — this locks that validation in.
 */
final class ScopeAssignmentModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_row_with_a_known_entity_type_and_no_grant_level_saves(): void
    {
        $assignment = ScopeAssignment::query()->create([
            'actor_identifier' => '1',
            'entity_type' => ScopeEntityType::CEMETERY,
            'entity_id' => '42',
        ]);

        $this->assertDatabaseHas('scope_assignments', [
            'id' => $assignment->id,
            'actor_identifier' => '1',
            'entity_type' => 'cemetery',
            'entity_id' => '42',
            'grant_level' => null,
        ]);
    }

    public function test_a_row_with_a_known_grant_level_saves(): void
    {
        $assignment = ScopeAssignment::query()->create([
            'actor_identifier' => '1',
            'entity_type' => ScopeEntityType::VENDOR,
            'entity_id' => '7',
            'grant_level' => ScopeGrantLevel::ASSIGNED,
        ]);

        $this->assertSame(ScopeGrantLevel::ASSIGNED, $assignment->fresh()->grant_level);
    }

    public function test_an_unknown_entity_type_is_rejected_on_save(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ScopeAssignment::query()->create([
            'actor_identifier' => '1',
            'entity_type' => 'spaceship',
            'entity_id' => '1',
        ]);
    }

    public function test_an_unknown_grant_level_is_rejected_on_save(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ScopeAssignment::query()->create([
            'actor_identifier' => '1',
            'entity_type' => ScopeEntityType::CEMETERY,
            'entity_id' => '1',
            'grant_level' => 'super-admin',
        ]);
    }

    public function test_a_fresh_assignment_is_active(): void
    {
        $assignment = ScopeAssignment::query()->create([
            'actor_identifier' => '1',
            'entity_type' => ScopeEntityType::CEMETERY,
            'entity_id' => '1',
        ]);

        $this->assertTrue($assignment->isActive());
        $this->assertNull($assignment->revoked_at);
    }

    public function test_revoke_sets_revoked_at_and_is_no_longer_active(): void
    {
        $assignment = ScopeAssignment::query()->create([
            'actor_identifier' => '1',
            'entity_type' => ScopeEntityType::CEMETERY,
            'entity_id' => '1',
        ]);

        $assignment->revoke();

        $this->assertFalse($assignment->isActive());
        $this->assertNotNull($assignment->revoked_at);
        $this->assertDatabaseHas('scope_assignments', [
            'id' => $assignment->id,
        ]);
        // Revoke is soft (revoked_at set), not a delete — the row remains.
        $this->assertSame(1, ScopeAssignment::query()->whereKey($assignment->id)->count());
    }
}
