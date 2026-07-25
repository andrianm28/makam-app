<?php

declare(strict_types=1);

namespace Tests\Feature\FeatureGate;

use App\Platform\FeatureGate\EloquentGateRegistrySource;
use App\Platform\FeatureGate\Models\FeatureGate;
use App\Platform\FeatureGate\Models\GateEnvironmentState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Exercises `EloquentGateRegistrySource` against the real database: the
 * seeded registry, an invalid `state` value (misconfigured), an
 * environment override, and a total load failure. requirements.md AC10
 * end-to-end through the actual query path, complementing
 * `GateRegistrySnapshotTest`'s pure-value-object proof.
 *
 * FIXED 26 Jul 2026: this doc block originally claimed SQLite as the test
 * driver — wrong. ci.yml's `php` job (the one that actually runs this
 * suite) sets `DB_CONNECTION=pgsql` against a real Postgres service
 * container; phpunit.xml's own default is irrelevant once that env var is
 * set. This mattered concretely: the total-load-failure test below
 * originally did a bare `Schema::drop('feature_gates')`, which is lenient
 * on SQLite (foreign keys often unenforced by default) but failed loudly
 * on the real Postgres CI run — `feature_flags`/`gate_activations`/
 * `gate_environment_state` all hold live FK constraints against this
 * table. Fixed with an explicit `CASCADE` drop.
 */
final class EloquentGateRegistrySourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeded_registry_loads_all_seventeen_gates_closed(): void
    {
        $snapshot = (new EloquentGateRegistrySource('testing'))->load();

        $this->assertCount(17, $snapshot->knownGateIds());
        $this->assertFalse($snapshot->isOpen('G-PAY-01'));
        $this->assertFalse($snapshot->loadFailed);
    }

    public function test_a_row_with_an_open_state_loads_as_open(): void
    {
        FeatureGate::query()->where('gate_id', 'G-PAY-01')->update(['state' => 'open']);

        $snapshot = (new EloquentGateRegistrySource('testing'))->load();

        $this->assertTrue($snapshot->isOpen('G-PAY-01'));
    }

    public function test_a_row_with_an_unrecognised_state_value_resolves_misconfigured_and_closed(): void
    {
        FeatureGate::query()->where('gate_id', 'G-PAY-01')->update(['state' => 'sideways']);

        $snapshot = (new EloquentGateRegistrySource('testing'))->load();

        $this->assertFalse($snapshot->isOpen('G-PAY-01'));
        $this->assertTrue($snapshot->stateFor('G-PAY-01')->misconfigured);
    }

    public function test_a_gate_id_absent_from_the_table_resolves_unknown_and_closed(): void
    {
        $snapshot = (new EloquentGateRegistrySource('testing'))->load();

        $this->assertFalse($snapshot->isOpen('G-THIS-GATE-ID-DOES-NOT-EXIST'));
        $this->assertTrue($snapshot->stateFor('G-THIS-GATE-ID-DOES-NOT-EXIST')->unknown);
    }

    public function test_an_environment_override_wins_over_the_global_state_for_the_matching_environment(): void
    {
        FeatureGate::query()->where('gate_id', 'G-PAY-01')->update(['state' => 'open']);

        GateEnvironmentState::query()->create([
            'gate_id' => 'G-PAY-01',
            'environment' => 'testing',
            'state' => 'closed',
        ]);

        $snapshot = (new EloquentGateRegistrySource('testing'))->load();

        $this->assertFalse($snapshot->isOpen('G-PAY-01'));
    }

    public function test_an_environment_override_for_a_different_environment_is_ignored(): void
    {
        // Global state closed; a 'production' override exists claiming
        // open, but this source is reading for 'testing' — AC11's "a
        // development activation [must not] imply staging or production
        // activation" data-source half.
        GateEnvironmentState::query()->create([
            'gate_id' => 'G-PAY-01',
            'environment' => 'production',
            'state' => 'open',
        ]);

        $snapshot = (new EloquentGateRegistrySource('testing'))->load();

        $this->assertFalse($snapshot->isOpen('G-PAY-01'));
    }

    public function test_a_total_registry_load_failure_denies_every_gate_instead_of_throwing(): void
    {
        // CASCADE: `feature_flags`, `gate_activations`, and
        // `gate_environment_state` all hold FK constraints against
        // `feature_gates.gate_id`. CASCADE drops those constraints along
        // with this table, not the other tables' rows — safe here since
        // the test ends (and RefreshDatabase rolls the whole transaction
        // back) immediately after this assertion.
        DB::statement('DROP TABLE feature_gates CASCADE');

        $snapshot = (new EloquentGateRegistrySource('testing'))->load();

        $this->assertTrue($snapshot->loadFailed);
        $this->assertFalse($snapshot->isOpen('G-PAY-01'));
        $this->assertSame([], $snapshot->knownGateIds());
    }
}
