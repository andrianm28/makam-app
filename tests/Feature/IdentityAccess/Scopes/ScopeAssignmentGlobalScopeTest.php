<?php

declare(strict_types=1);

namespace Tests\Feature\IdentityAccess\Scopes;

use App\Models\User;
use App\Platform\IdentityAccess\Scopes\Models\ScopeAssignment;
use App\Platform\IdentityAccess\Scopes\ScopeAssignmentGlobalScope;
use App\Platform\IdentityAccess\Scopes\ScopeEntityType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\CreatesScopedTestModelTable;
use Tests\Fixtures\ScopedTestModel;
use Tests\TestCase;

/**
 * The proof this whole batch exists for — requirements.md AC5 ("enforce
 * record scope at query level") and its Negative criterion ("No
 * cross-scope read reachable by changing an identifier in a URL"), verified
 * end-to-end: `HasScopeAssignments` attached to an arbitrary model
 * (`Tests\Fixtures\ScopedTestModel`, per the batch brief — never a real
 * domain model), driven through the REAL, already-merged Batch 3.1
 * `ActorContext`/`ActorContextResolver` wiring via the standard
 * `actingAs()` test helper (no mock of any file this batch does not own).
 */
final class ScopeAssignmentGlobalScopeTest extends TestCase
{
    use CreatesScopedTestModelTable;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpScopedTestModelTable();
    }

    public function test_guest_sees_no_rows_even_when_rows_exist(): void
    {
        $model = ScopedTestModel::query()->create(['name' => 'Alpha']);

        $this->assertNull(ScopedTestModel::query()->find($model->id));
        $this->assertCount(0, ScopedTestModel::all());
    }

    public function test_authenticated_actor_with_zero_grants_sees_nothing_closed_by_default(): void
    {
        // The state EVERY actor is in today (nothing writes scope_
        // assignments yet) — this is the single most important assertion
        // in this file: closed-by-default, not open-by-default.
        $model = ScopedTestModel::query()->create(['name' => 'Alpha']);
        $this->actingAs(User::factory()->create());

        $this->assertNull(ScopedTestModel::query()->find($model->id));
        $this->assertCount(0, ScopedTestModel::all());
    }

    public function test_actor_with_an_active_grant_sees_the_granted_row(): void
    {
        $model = ScopedTestModel::query()->create(['name' => 'Alpha']);
        $user = User::factory()->create();

        ScopeAssignment::query()->create([
            'actor_identifier' => (string) $user->id,
            'entity_type' => ScopeEntityType::CEMETERY,
            'entity_id' => (string) $model->id,
        ]);
        $this->actingAs($user);

        $found = ScopedTestModel::query()->find($model->id);

        $this->assertNotNull($found);
        $this->assertSame($model->id, $found->id);
    }

    public function test_actor_cannot_reach_an_ungranted_row_by_changing_the_identifier(): void
    {
        // requirements.md's Negative criterion, directly: same actor, same
        // request shape, only the id changes — a granted record is
        // reachable, an ungranted one is not, even though both rows exist.
        $granted = ScopedTestModel::query()->create(['name' => 'Alpha']);
        $other = ScopedTestModel::query()->create(['name' => 'Bravo']);
        $user = User::factory()->create();

        ScopeAssignment::query()->create([
            'actor_identifier' => (string) $user->id,
            'entity_type' => ScopeEntityType::CEMETERY,
            'entity_id' => (string) $granted->id,
        ]);
        $this->actingAs($user);

        $this->assertNotNull(ScopedTestModel::query()->find($granted->id));
        $this->assertNull(ScopedTestModel::query()->find($other->id));
    }

    public function test_revoked_grant_no_longer_grants_access(): void
    {
        $model = ScopedTestModel::query()->create(['name' => 'Alpha']);
        $user = User::factory()->create();

        $assignment = ScopeAssignment::query()->create([
            'actor_identifier' => (string) $user->id,
            'entity_type' => ScopeEntityType::CEMETERY,
            'entity_id' => (string) $model->id,
        ]);
        $this->actingAs($user);

        $this->assertNotNull(ScopedTestModel::query()->find($model->id));

        $assignment->revoke();

        $this->assertNull(ScopedTestModel::query()->find($model->id));
    }

    public function test_a_grant_for_one_actor_does_not_leak_to_another_actor(): void
    {
        $model = ScopedTestModel::query()->create(['name' => 'Alpha']);
        $owner = User::factory()->create();
        $other = User::factory()->create();

        ScopeAssignment::query()->create([
            'actor_identifier' => (string) $owner->id,
            'entity_type' => ScopeEntityType::CEMETERY,
            'entity_id' => (string) $model->id,
        ]);
        $this->actingAs($other);

        $this->assertNull(ScopedTestModel::query()->find($model->id));
    }

    public function test_a_grant_recorded_under_a_different_entity_type_does_not_grant_this_model(): void
    {
        // Defensive: a grant is scoped by (actor, entity_type, entity_id)
        // together — a matching id under the wrong entity_type must not
        // leak into an unrelated model that happens to share that id.
        $model = ScopedTestModel::query()->create(['name' => 'Alpha']);
        $user = User::factory()->create();

        ScopeAssignment::query()->create([
            'actor_identifier' => (string) $user->id,
            'entity_type' => ScopeEntityType::VENDOR,
            'entity_id' => (string) $model->id,
        ]);
        $this->actingAs($user);

        $this->assertNull(ScopedTestModel::query()->find($model->id));
    }

    public function test_without_global_scope_bypasses_the_constraint(): void
    {
        // Confirms this is a real, named Eloquent global scope (registered
        // under ScopeAssignmentGlobalScope::class) rather than a hardcoded
        // query modifier — and documents the explicit, intentional escape
        // hatch a future internal/system job would use.
        $model = ScopedTestModel::query()->create(['name' => 'Alpha']);

        $this->assertNull(ScopedTestModel::query()->find($model->id));

        $found = ScopedTestModel::withoutGlobalScope(ScopeAssignmentGlobalScope::class)->find($model->id);

        $this->assertNotNull($found);
    }

    public function test_a_listing_query_returns_exactly_the_granted_rows_out_of_a_mixed_set(): void
    {
        // Batch 3.5b gap-fill: every test above only ever checks a SINGLE
        // row at a time ("is row X visible: yes/no"), via ->find(). None of
        // them prove the global scope's whereIn() constraint behaves
        // correctly across a real multi-row RESULT SET returned from a
        // plain listing query — the actual shape a table/index page issues.
        // Three rows, only two granted: the result must be exactly those
        // two, not zero, not three, and not the wrong two.
        $granted1 = ScopedTestModel::query()->create(['name' => 'Alpha']);
        $granted2 = ScopedTestModel::query()->create(['name' => 'Bravo']);
        $ungranted = ScopedTestModel::query()->create(['name' => 'Charlie']);
        $user = User::factory()->create();

        foreach ([$granted1, $granted2] as $model) {
            ScopeAssignment::query()->create([
                'actor_identifier' => (string) $user->id,
                'entity_type' => ScopeEntityType::CEMETERY,
                'entity_id' => (string) $model->id,
            ]);
        }
        $this->actingAs($user);

        $visibleIds = ScopedTestModel::all()->pluck('id')->sort()->values()->all();

        $this->assertSame(
            [$granted1->id, $granted2->id],
            $visibleIds,
            'A listing query must return exactly the granted rows — not zero, not all three, not the ungranted row.'
        );
        $this->assertCount(2, $visibleIds);
        $this->assertNotContains($ungranted->id, $visibleIds);
    }
}
