<?php

declare(strict_types=1);

namespace App\Platform\FeatureGate;

use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditOutcome;
use App\Platform\Audit\AuditSource;
use App\Platform\Audit\AuditSubject;
use App\Platform\Correlation\CorrelationContext;
use App\Platform\FeatureGate\Events\GateStateChanged;
use App\Platform\FeatureGate\Exceptions\MissingActivationEvidenceException;
use App\Platform\FeatureGate\Models\FeatureGate;
use App\Platform\FeatureGate\Models\GateActivation;
use App\Platform\Outbox\Outbox;
use App\Platform\Outbox\OutboxClassification;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Writes an audited gate state change: updates `feature_gates.state` and
 * appends the matching `gate_activations` row, in one transaction — the
 * ONLY path allowed to change a gate's state (requirements.md AC3/AC4's
 * "who/when/evidence/reason" data shape; design.md's Change flow: "Privileged
 * action -> recent re-authentication -> evidence reference required ->
 * audited -> outbox event emitted -> per-request caches invalidated").
 *
 * ---------------------------------------------------------------------------
 * What THIS class enforces today, and what it explicitly does not (read
 * this before wiring a controller/Livewire action to it)
 * ---------------------------------------------------------------------------
 * Enforced here: evidence reference and reason are both required and
 * non-blank (Negative criteria: "No gate activation without recorded
 * evidence, owner, and date" — "owner" is `feature_gates.owner`, already on
 * the row this method updates; "date" is `$occurredAt`, always
 * server-generated, never caller-supplied). `to_state` must be one of the
 * two recognised values. The whole write is one DB transaction with a
 * row lock (`lockForUpdate()`) on the gate so two concurrent activations of
 * the same gate cannot race each other's `from_state`.
 *
 * NOT enforced here — deferred by this batch's explicit brief, not
 * silently skipped:
 *
 * - AC4's "recent re-authentication" requirement. The middleware that would
 *   check `ActorContext::$lastAuthenticatedAt` against a freshness window
 *   does not exist yet (S3-T2/T3, human-gated — see
 *   `app/Platform/IdentityAccess/ActorContext.php`'s own `$mfaState` note
 *   for the same gap from the identity side). Whoever builds the
 *   controller/action that calls `record()` MUST put that middleware in
 *   front of the route once S3-T3 lands; `record()` itself has no way to
 *   verify freshness and must not be trusted as the enforcement point for
 *   it.
 *
 * AC9 (outbox event emission) and AC12 (audit trail integration) are now
 * both wired for real — see the `Outbox::record()` and `Audit::record()`
 * calls below, in the same transaction as the `feature_gates` update and
 * the `gate_activations` insert (Sprint 3 Batch 3.5, closing finding N-9
 * item (3)). `feature_gate.state_changed.v1` is NOT a catalogued event in
 * `docs/contracts/event-catalog.md` as of this writing — that gap is
 * recorded as finding N-12, not silently papered over. See the `Outbox::
 * record()` call below for the full reasoning.
 */
final readonly class GateActivationRecorder
{
    private const array VALID_STATES = ['open', 'closed'];

    /**
     * @param  string  $actorRole  AC2's mandatory `actor_role` for the audit
     *                             record this write now also produces. This class has no
     *                             independent way to learn a caller's role — `$actorReference`
     *                             is a bare identity reference, not a role — so it defaults to
     *                             `'admin'`, the only role this class's own doc block already
     *                             names as authorized to trigger a gate state change
     *                             ("Privileged action -> recent re-authentication -> ..."). A
     *                             future non-panel caller (e.g. an automated job that closes a
     *                             gate on an external signal) can override this explicitly
     *                             rather than this class guessing a different default.
     * @param  AuditSource  $auditSource  AC2's mandatory audit source. Defaults to
     *                                    `AuditSource::Panel` for the identical reason — every real
     *                                    caller of this class today is the future admin gate-
     *                                    management screen this batch does not build.
     */
    public function record(
        string $gateId,
        int|string $actorReference,
        string $toState,
        string $evidenceReference,
        string $reason,
        string $actorRole = 'admin',
        AuditSource $auditSource = AuditSource::Panel,
    ): GateActivation {
        if (trim($evidenceReference) === '') {
            throw new MissingActivationEvidenceException(
                "Gate activation for [{$gateId}] requires a non-blank evidence reference."
            );
        }

        if (trim($reason) === '') {
            throw new MissingActivationEvidenceException(
                "Gate activation for [{$gateId}] requires a non-blank reason."
            );
        }

        if (! in_array($toState, self::VALID_STATES, true)) {
            throw new InvalidArgumentException(
                'Gate activation target state must be one of: '.implode(', ', self::VALID_STATES).". Got [{$toState}]."
            );
        }

        return DB::transaction(function () use (
            $gateId,
            $actorReference,
            $toState,
            $evidenceReference,
            $reason,
            $actorRole,
            $auditSource,
        ): GateActivation {
            /** @var FeatureGate $gate */
            $gate = FeatureGate::query()->lockForUpdate()->findOrFail($gateId);

            $fromState = $gate->state;
            $occurredAt = CarbonImmutable::now();

            $activation = GateActivation::create([
                'gate_id' => $gateId,
                'actor_reference' => (string) $actorReference,
                'evidence_reference' => $evidenceReference,
                'reason' => $reason,
                'from_state' => $fromState,
                'to_state' => $toState,
                'occurred_at' => $occurredAt,
            ]);

            $gate->forceFill([
                'state' => $toState,
                'evidence_reference' => $evidenceReference,
                'effective_at' => $occurredAt,
            ])->save();

            // AC9: "WHEN a gate state changes THE SYSTEM SHALL emit an
            // outbox event." Same transaction as the two writes above —
            // the outbox pattern's whole point (event and state change
            // commit atomically or not at all). Payload shaped exactly
            // like `GateStateChanged`'s public properties, per that
            // class's own doc block.
            //
            // Event name: `docs/contracts/event-catalog.md` has NO entry
            // for a gate-state-change event (closest analogues are
            // `cemetery.capability_changed.v1` and other domain-specific
            // events — nothing named anything like
            // `feature_gate.state_changed`). Per `platform-outbox` AC3
            // ("use event types from event-catalog.md and SHALL NOT
            // restate the catalogue") this class does not invent a
            // catalogue entry unilaterally. AC9's actual requirement is
            // just "emit an outbox event," not "use a catalogued name" —
            // AC3 constrains the outbox module's own general behaviour,
            // not this specific write. So: the row is still written, using
            // a clearly-provisional name following the catalogue's own
            // `noun.verb_past_tense.vN` convention. Recorded as a genuine,
            // uncatalogued cross-document gap in `docs/planning/
            // sprint-plan.md` finding N-12, not silently papered over.
            $event = new GateStateChanged(
                gateId: $gateId,
                fromState: $fromState,
                toState: $toState,
                evidenceReference: $evidenceReference,
                occurredAt: $occurredAt->toIso8601String(),
            );

            Outbox::record(
                eventName: 'feature_gate.state_changed.v1',
                eventVersion: 1,
                aggregateType: 'feature_gate',
                aggregateId: $event->gateId,
                data: [
                    'gate_id' => $event->gateId,
                    'from_state' => $event->fromState,
                    'to_state' => $event->toState,
                    'evidence_reference' => $event->evidenceReference,
                    'occurred_at' => $event->occurredAt,
                ],
                // A gate state change is operational/internal data — not a
                // PUBLIC broadcast, and not RESTRICTED/CONFIDENTIAL content
                // (no KTP/KK/bank/credential-shaped field appears in the
                // payload above; PayloadClassification's denylist would
                // reject any of those regardless). INTERNAL matches
                // `OutboxClassification`'s own doc block: "the caller's
                // declared classification," judged here by the producer
                // (this class), which is exactly the party that
                // classification doc block says owns that judgement.
                classification: OutboxClassification::Internal,
                // Deterministic per-activation key, not per-gate: the same
                // gate can legitimately be activated/deactivated many
                // times, and each is a distinct event, not a replay of the
                // previous one. `$activation->id` is only known after the
                // insert above, so it is available here.
                idempotencyKey: "feature_gate:{$gateId}:activation:{$activation->id}",
            );

            // AC12: "WHEN a gate decision affects a money path or a legal
            // capability THE SYSTEM SHALL audit it with the evidence
            // reference." Same transaction as the writes above — AC4's
            // "mutation and its audit record cannot be committed
            // separately" discipline (`Audit::wrap()`'s own doc block),
            // applied here via a direct `Audit::record()` call rather than
            // `Audit::wrap()` because the mutation (gate save + activation
            // insert + outbox write) is already mid-transaction by the
            // time the audit record is ready to be written — `wrap()`
            // would just open a second, redundant transaction around code
            // that must already share this one.
            //
            // Action name: `GATE_CHANGE` is already declared on
            // `SensitiveActions::ACTIONS` (added when that list was
            // authored — see that class's own file), so `Audit::record()`
            // enforces a non-blank `$reason` on this action too, as
            // defense in depth alongside this method's own blank-reason
            // check above. No change to `SensitiveActions` was needed.
            Audit::record(
                action: 'GATE_CHANGE',
                subject: new AuditSubject(type: 'feature_gate', id: $gateId),
                outcome: AuditOutcome::Allowed,
                actorRef: $actorReference,
                actorRole: $actorRole,
                source: $auditSource,
                reason: $reason,
                correlationId: app(CorrelationContext::class)->current()?->value,
                metadata: [
                    'reference_number' => $evidenceReference,
                    'previous_state' => $fromState,
                    'new_state' => $toState,
                ],
            );

            return $activation;
        });
    }
}
