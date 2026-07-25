<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\FeatureGate;

use App\Platform\FeatureGate\GateRegistrySnapshot;
use App\Platform\FeatureGate\GateState;
use Tests\TestCase;

/**
 * THE single highest-value test in this batch (per this batch's brief):
 * proof that an unknown or misconfigured gate id resolves closed, at the
 * exact seam every other consumer in this module (`FeatureGateResolver`,
 * `ModeResolver`, every `Modes\*::fromGateOpen()`) ultimately reads
 * through. requirements.md AC10: "WHEN a gate is unknown or misconfigured
 * THE SYSTEM SHALL resolve it closed; THE SYSTEM SHALL NOT resolve it
 * open — evaluation is deny-by-default." Negative criteria: "No gate
 * defaulting to open on misconfiguration."
 *
 * Deliberately a pure unit test — no database, no container, no
 * `EloquentGateRegistrySource`. `GateRegistrySnapshot` is a plain value
 * object; if deny-by-default were ever wrong, it would be wrong HERE,
 * independent of whatever the database happens to contain on any given
 * test run.
 */
final class GateRegistrySnapshotTest extends TestCase
{
    public function test_a_gate_id_never_seen_by_the_snapshot_resolves_closed(): void
    {
        $snapshot = GateRegistrySnapshot::empty();

        $this->assertFalse($snapshot->isOpen('G-PAY-01'));
        $this->assertTrue($snapshot->stateFor('G-PAY-01')->unknown);
    }

    public function test_a_gate_id_never_seen_by_a_non_empty_snapshot_still_resolves_closed(): void
    {
        // Proves the deny-by-default fallback applies per-gate-id, not just
        // to a globally-empty snapshot — a snapshot that legitimately knows
        // about SOME gates must still deny a completely unrelated,
        // never-registered id, exactly as if the whole registry were empty.
        $snapshot = new GateRegistrySnapshot([
            'G-PAY-01' => GateState::fromRecord('G-PAY-01', open: true),
        ]);

        $this->assertTrue($snapshot->isOpen('G-PAY-01'));
        $this->assertFalse($snapshot->isOpen('G-THIS-GATE-ID-WAS-NEVER-REGISTERED'));
        $this->assertTrue($snapshot->stateFor('G-THIS-GATE-ID-WAS-NEVER-REGISTERED')->unknown);
    }

    public function test_a_misconfigured_gate_resolves_closed_even_though_a_row_exists(): void
    {
        // Distinguishes "no row" from "a row exists but could not be
        // trusted" — both must resolve closed, but for a different
        // diagnostic reason (unknown vs misconfigured). A misconfigured
        // gate NEVER falls back to open just because a row was present.
        $snapshot = new GateRegistrySnapshot([
            'G-PAY-01' => GateState::misconfigured('G-PAY-01'),
        ]);

        $this->assertFalse($snapshot->isOpen('G-PAY-01'));
        $this->assertTrue($snapshot->stateFor('G-PAY-01')->misconfigured);
        $this->assertFalse($snapshot->stateFor('G-PAY-01')->unknown);
    }

    public function test_an_empty_snapshot_from_a_load_failure_denies_every_gate_id(): void
    {
        // EloquentGateRegistrySource::load() returns exactly this shape
        // when the underlying registry query throws (DB down, table
        // missing) — proves the whole-registry failure mode is not
        // distinguishable from "everything is unknown" at the isOpen()
        // call site, which is the point: a caller cannot treat
        // infrastructure failure as "probably fine, let it through".
        $snapshot = GateRegistrySnapshot::empty(loadFailed: true);

        $this->assertTrue($snapshot->loadFailed);
        foreach (['G-PAY-01', 'G-WA-01', 'G-LEGAL-01', 'G-DATA-01'] as $gateId) {
            $this->assertFalse($snapshot->isOpen($gateId));
        }
    }

    public function test_known_gate_ids_lists_only_real_rows_not_every_id_ever_queried(): void
    {
        $snapshot = new GateRegistrySnapshot([
            'G-PAY-01' => GateState::fromRecord('G-PAY-01', open: false),
        ]);

        $this->assertSame(['G-PAY-01'], $snapshot->knownGateIds());

        // Querying an unregistered id does not mutate the snapshot's known
        // set — deny-by-default is computed on the fly, never memoised as
        // a new "known" entry.
        $snapshot->isOpen('G-UNKNOWN');
        $this->assertSame(['G-PAY-01'], $snapshot->knownGateIds());
    }
}
