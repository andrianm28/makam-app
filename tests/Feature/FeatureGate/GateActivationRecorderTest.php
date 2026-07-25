<?php

declare(strict_types=1);

namespace Tests\Feature\FeatureGate;

use App\Platform\FeatureGate\GateActivationRecorder;
use App\Platform\FeatureGate\Models\FeatureGate;
use App\Platform\FeatureGate\Models\GateActivation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The success path and append-only contract for `GateActivationRecorder` —
 * design.md: "`gate_activations` is append-only: actor, evidence ref,
 * reason, from/to state ... history is never rewritten." The rejection
 * paths (blank evidence/reason, invalid target state) are covered by
 * `tests/Unit/Platform/FeatureGate/GateActivationRecorderValidationTest.php`
 * since those do not touch the database.
 *
 * What this test does NOT cover (explicitly out of this batch's scope —
 * see `GateActivationRecorder`'s own doc block): AC4's recent-re-
 * authentication requirement, AC9's outbox emission, AC12's real audit
 * integration.
 */
final class GateActivationRecorderTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_valid_activation_updates_the_gate_and_appends_an_activation_row(): void
    {
        $recorder = new GateActivationRecorder;

        $activation = $recorder->record(
            gateId: 'G-PAY-01',
            actorReference: 7,
            toState: 'open',
            evidenceReference: 'MERCHANT-CONTRACT-2026-07-25',
            reason: 'Merchant of record contract signed; reconciliation SOP approved.',
        );

        $gate = FeatureGate::query()->findOrFail('G-PAY-01');
        $this->assertSame('open', $gate->state);
        $this->assertSame('MERCHANT-CONTRACT-2026-07-25', $gate->evidence_reference);
        $this->assertNotNull($gate->effective_at);

        $this->assertSame('G-PAY-01', $activation->gate_id);
        $this->assertSame('7', $activation->actor_reference);
        $this->assertSame('closed', $activation->from_state);
        $this->assertSame('open', $activation->to_state);
        $this->assertSame('MERCHANT-CONTRACT-2026-07-25', $activation->evidence_reference);
        $this->assertNotNull($activation->occurred_at);

        $this->assertDatabaseCount('gate_activations', 1);
    }

    public function test_repeated_activation_appends_a_new_row_instead_of_rewriting_the_previous_one(): void
    {
        $recorder = new GateActivationRecorder;

        $recorder->record('G-PAY-01', 7, 'open', 'DOC-1', 'Go live.');
        $recorder->record('G-PAY-01', 9, 'closed', 'DOC-2', 'Provider incident — rollback.');

        $this->assertDatabaseCount('gate_activations', 2);

        $rows = GateActivation::query()->orderBy('id')->get();
        $this->assertSame('closed', $rows[0]->from_state);
        $this->assertSame('open', $rows[0]->to_state);
        $this->assertSame('open', $rows[1]->from_state);
        $this->assertSame('closed', $rows[1]->to_state);

        $this->assertSame('closed', FeatureGate::query()->findOrFail('G-PAY-01')->state);
    }

    public function test_gate_activations_table_has_no_updated_at_column(): void
    {
        // Structural proof of "append-only, history is never rewritten" —
        // there is no column an in-place update could even target.
        $this->assertFalse(Schema::hasColumn('gate_activations', 'updated_at'));
        $this->assertTrue(Schema::hasColumn('gate_activations', 'created_at'));
        $this->assertNull(GateActivation::UPDATED_AT);
    }
}
