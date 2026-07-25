<?php

declare(strict_types=1);

namespace App\Platform\FeatureGate\Events;

/**
 * Extension point for requirements.md AC9: "WHEN a gate state changes THE
 * SYSTEM SHALL emit an outbox event so dependent projections and
 * notifications react."
 *
 * NOTHING IN THIS CODEBASE DISPATCHES THIS EVENT YET. That is deliberate,
 * not an oversight — this batch's brief is explicit that AC9 is out of
 * scope: the outbox (`outbox_events` table, `SKIP LOCKED` claim/publish
 * loop) does not exist yet (S3-T11, Batch 3.4, a later batch this one
 * cannot depend on). Dispatching this through Laravel's plain in-process
 * event bus instead would be worse than doing nothing: an in-process
 * listener has no durability guarantee across a crashed worker, so it
 * would look like AC9 is satisfied while actually providing none of the
 * "commit succeeds, dispatcher dies, event still publishes on recovery"
 * guarantee that is the entire reason S3-T11 introduces an outbox at all
 * (agent-execution-plan.md §5, Batch 3.4).
 *
 * The intended integration, once S3-T11 lands: `GateActivationRecorder`
 * writes an `outbox_events` row in the SAME database transaction as the
 * `feature_gates` update and the `gate_activations` insert (the outbox
 * pattern's whole point — the event and the state change commit
 * atomically or not at all), using this class's public properties as the
 * event payload shape. See `GateActivationRecorder::record()` for the
 * exact TODO marking where that write would go.
 */
final readonly class GateStateChanged
{
    public function __construct(
        public string $gateId,
        public string $fromState,
        public string $toState,
        public string $evidenceReference,
        public string $occurredAt,
    ) {}
}
