<?php

declare(strict_types=1);

namespace Tests\Feature\FeatureGate;

use App\Platform\FeatureGate\EloquentGateRegistrySource;
use App\Platform\FeatureGate\Models\FeatureGate;
use App\Platform\FeatureGate\Models\GateEnvironmentState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Exercises `EloquentGateRegistrySource` against the real (SQLite, test
 * driver — phpunit.xml) database: the seeded registry, an invalid `state`
 * value (misconfigured), an environment override, and a total load
 * failure. requirements.md AC10 end-to-end through the actual query path,
 * complementing `GateRegistrySnapshotTest`'s pure-value-object proof.
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
        Schema::drop('feature_gates');

        $snapshot = (new EloquentGateRegistrySource('testing'))->load();

        $this->assertTrue($snapshot->loadFailed);
        $this->assertFalse($snapshot->isOpen('G-PAY-01'));
        $this->assertSame([], $snapshot->knownGateIds());
    }
}
