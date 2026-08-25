<?php

declare(strict_types=1);

namespace Tests\Feature\FeatureGate;

use App\Platform\Audit\Models\AuditEvent;
use App\Platform\FeatureGate\GateActivationRecorder;
use App\Platform\FeatureGate\Models\FeatureGate;
use App\Platform\FeatureGate\Models\GateActivation;
use App\Platform\Outbox\Models\OutboxEvent;
use App\Platform\Outbox\Outbox;
use App\Platform\Outbox\OutboxClassification;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

/**
 * The success path, the append-only contract, and the AC9/AC12 same-
 * transaction integration for `GateActivationRecorder` — design.md:
 * "`gate_activations` is append-only: actor, evidence ref, reason,
 * from/to state ... history is never rewritten"; requirements.md AC9
 * ("emit an outbox event") and AC12 ("audit it with the evidence
 * reference"). The rejection paths (blank evidence/reason, invalid
 * target state) are covered by
 * `tests/Unit/Platform/FeatureGate/GateActivationRecorderValidationTest.php`
 * since those do not touch the database.
 *
 * What this test does NOT cover (explicitly out of this batch's scope —
 * see `GateActivationRecorder`'s own doc block): AC4's recent-re-
 * authentication requirement.
 */
final class GateActivationRecorderTest extends TestCase
{
    use RefreshDatabase;

    /**
     * `test_an_audit_write_failure_rolls_back_...` below registers a
     * static `AuditEvent::creating()` listener to simulate a downstream
     * write failure. Eloquent model event listeners are process-global
     * state, not reset between tests by `RefreshDatabase` or by Laravel's
     * own container rebuild — without this teardown, that listener would
     * silently survive into every later test in the same PHPUnit process
     * (this class and any other) and make every subsequent `AuditEvent`
     * creation throw "simulated audit write failure," regardless of test
     * order. Flushing unconditionally after every test in this class,
     * rather than only inside that one test, is deliberate: it is
     * correct even if that test fails before reaching its own assertions.
     */
    protected function tearDown(): void
    {
        AuditEvent::flushEventListeners();

        parent::tearDown();
    }

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

    public function test_a_successful_activation_writes_exactly_one_audit_row_and_one_outbox_row_in_the_same_transaction(): void
    {
        $recorder = new GateActivationRecorder;

        $activation = $recorder->record(
            gateId: 'G-PAY-01',
            actorReference: 7,
            toState: 'open',
            evidenceReference: 'MERCHANT-CONTRACT-2026-07-25',
            reason: 'Merchant of record contract signed; reconciliation SOP approved.',
        );

        $this->assertSame(1, AuditEvent::query()->count());
        $this->assertSame(1, OutboxEvent::query()->count());

        $auditEvent = AuditEvent::query()->sole();
        $this->assertSame('7', $auditEvent->actor_ref);
        $this->assertSame('admin', $auditEvent->actor_role);
        $this->assertSame('GATE_CHANGE', $auditEvent->action);
        $this->assertSame('panel', $auditEvent->source);
        $this->assertSame('feature_gate', $auditEvent->subject_type);
        $this->assertSame('G-PAY-01', $auditEvent->subject_id);
        $this->assertSame('allowed', $auditEvent->outcome);
        $this->assertSame('Merchant of record contract signed; reconciliation SOP approved.', $auditEvent->reason);
        $this->assertSame([
            'reference_number' => 'MERCHANT-CONTRACT-2026-07-25',
            'previous_state' => 'closed',
            'new_state' => 'open',
        ], $auditEvent->metadata);

        $outboxEvent = OutboxEvent::query()->sole();
        $this->assertSame('feature_gate.state_changed.v1', $outboxEvent->event_name);
        $this->assertSame(1, $outboxEvent->event_version);
        $this->assertSame('feature_gate', $outboxEvent->aggregate_type);
        $this->assertSame('G-PAY-01', $outboxEvent->aggregate_id);
        // assertEquals, not assertSame: outbox_events.payload is a
        // Postgres `jsonb` column (the migration's own choice, unlike
        // audit_events.metadata's plain `json`), and jsonb does not
        // preserve object key order on round-trip — Postgres's own docs:
        // "jsonb does not preserve the order of object keys." assertSame
        // on an array is order-sensitive (PHP's `===` for arrays compares
        // key order too), so it fails here for a reason that has nothing
        // to do with correctness. First real Postgres CI run caught this.
        $this->assertEquals([
            'gate_id' => 'G-PAY-01',
            'from_state' => 'closed',
            'to_state' => 'open',
            'evidence_reference' => 'MERCHANT-CONTRACT-2026-07-25',
            'occurred_at' => $activation->occurred_at->toIso8601String(),
        ], $outboxEvent->payload);
        $this->assertSame(OutboxClassification::Internal->value, $outboxEvent->classification);
        $this->assertSame("feature_gate:G-PAY-01:activation:{$activation->id}", $outboxEvent->idempotency_key);
    }

    public function test_a_colliding_outbox_idempotency_key_rolls_back_the_gate_change_and_the_activation_row(): void
    {
        // Realistic Outbox-side failure trigger, matching Outbox::record()'s
        // own doc block: "must be unique across the table when non-null —
        // an INSERT with a colliding key throws a database-layer exception."
        //
        // The colliding key must target the NEXT gate_activations id this
        // test's own second `record()` call will actually get — not a
        // hardcoded guess like `...activation:1`. Postgres SERIAL/BIGSERIAL
        // sequences are NOT transactional: `RefreshDatabase` rolls back
        // each test's own inserts, but does not reset the sequence's
        // `nextval()` counter, so an earlier (rolled-back) test in the same
        // CI run can leave `gate_activations_id_seq` already advanced past
        // 1 by the time this test runs — a hardcoded "...activation:1"
        // silently stops colliding and this test's own `$this->fail(...)`
        // line fires instead (first real Postgres CI run caught exactly
        // this). Deriving the target id from a REAL row created inside this
        // test's own transaction sidesteps the sequence-drift problem
        // entirely: whatever id Postgres assigns this call, the next one is
        // one more, regardless of what any other test already consumed.
        $recorder = new GateActivationRecorder;

        $first = $recorder->record(
            gateId: 'G-PAY-01',
            actorReference: 7,
            toState: 'open',
            evidenceReference: 'MERCHANT-CONTRACT-2026-07-25',
            reason: 'Merchant of record contract signed; reconciliation SOP approved.',
        );

        // GateActivationRecorder's SECOND call (below) will insert
        // gate_activations row id `$first->id + 1` and derive its outbox
        // idempotency key from that — pre-claim exactly that key.
        Outbox::record(
            eventName: 'fixture.collision.v1',
            eventVersion: 1,
            aggregateType: 'fixture',
            aggregateId: 1,
            data: [],
            classification: OutboxClassification::Internal,
            idempotencyKey: 'feature_gate:G-PAY-01:activation:'.($first->id + 1),
        );

        try {
            $recorder->record(
                gateId: 'G-PAY-01',
                actorReference: 7,
                toState: 'closed',
                evidenceReference: 'PROVIDER-INCIDENT-2026-07-26',
                reason: 'Provider incident — rollback for safety.',
            );

            $this->fail('Expected the colliding idempotency key to throw a QueryException.');
        } catch (QueryException) {
            // Expected — the simulated Outbox-side failure.
        }

        // The first (successful) activation must stand — this test proves
        // the SECOND call's own writes roll back, not that the whole test
        // has no state. The gate must still read 'open' (from the first
        // call), never 'closed' (the second call's target state, which
        // must never have committed).
        $this->assertSame('open', FeatureGate::query()->findOrFail('G-PAY-01')->state);
        $this->assertSame(1, GateActivation::query()->count());
        $this->assertSame(1, AuditEvent::query()->count());
        // Two outbox rows: the first call's real event, plus our one
        // manually pre-inserted collision row. The second call's own
        // Outbox::record() never committed.
        $this->assertSame(2, OutboxEvent::query()->count());
    }

    public function test_an_audit_write_failure_rolls_back_the_gate_change_the_activation_row_and_the_outbox_row(): void
    {
        // Realistic Audit-side failure trigger: GateActivationRecorder's
        // own blank-evidence/blank-reason checks (and SensitiveActions'
        // matching check inside Audit::record() itself) mean a
        // reason/metadata-shaped failure can never actually reach
        // Audit::record() through this class's public API — both
        // AuditReasonRequiredException and AuditMetadataKeyNotAllowedException
        // are therefore unreachable from here by design, not an oversight.
        // A `creating` model hook is used instead to simulate a downstream
        // write failure (e.g. a real database-layer error) at the exact
        // point `Audit::record()`'s own `AuditEvent::create()` call would
        // run — same "force the second write to fail after the first one
        // already ran, inside the same transaction" shape as
        // AuditWrapTransactionTest's own precedent, extended here to a
        // third participant (the outbox row, written just before this).
        AuditEvent::creating(function (): void {
            throw new RuntimeException('simulated audit write failure');
        });

        $recorder = new GateActivationRecorder;

        try {
            $recorder->record(
                gateId: 'G-PAY-01',
                actorReference: 7,
                toState: 'open',
                evidenceReference: 'MERCHANT-CONTRACT-2026-07-25',
                reason: 'Merchant of record contract signed; reconciliation SOP approved.',
            );

            $this->fail('Expected the simulated audit write failure to propagate.');
        } catch (RuntimeException $exception) {
            $this->assertSame('simulated audit write failure', $exception->getMessage());
        }

        // Outbox::record() runs BEFORE Audit::record() in
        // GateActivationRecorder, so its row would already have been
        // written when Audit::record() throws — proving the whole
        // transaction, not just the audit write, rolls back.
        $this->assertSame('closed', FeatureGate::query()->findOrFail('G-PAY-01')->state);
        $this->assertSame(0, GateActivation::query()->count());
        $this->assertSame(0, OutboxEvent::query()->count());
        $this->assertSame(0, AuditEvent::query()->count());
    }
}
