<?php

declare(strict_types=1);

namespace App\Platform\FeatureGate\Events;

/**
 * Payload shape for requirements.md AC9: "WHEN a gate state changes THE
 * SYSTEM SHALL emit an outbox event so dependent projections and
 * notifications react."
 *
 * This class is never dispatched through Laravel's plain in-process event
 * bus — an in-process listener has no durability guarantee across a
 * crashed worker, so it would look like AC9 is satisfied while actually
 * providing none of the "commit succeeds, dispatcher dies, event still
 * publishes on recovery" guarantee the outbox exists for
 * (`docs/planning/agent-execution-plan.md` §5, Batch 3.4). Instead, it is
 * a plain DTO: `GateActivationRecorder::record()` (Batch 3.5, once S3-T11's
 * outbox landed) constructs one of these and copies its public properties
 * into the `data` payload of a real `Outbox::record()` call, in the same
 * database transaction as the `feature_gates` update and the
 * `gate_activations` insert — see that method for the real integration.
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
