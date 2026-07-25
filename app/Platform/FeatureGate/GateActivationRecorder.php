<?php

declare(strict_types=1);

namespace App\Platform\FeatureGate;

use App\Platform\FeatureGate\Events\GateStateChanged;
use App\Platform\FeatureGate\Exceptions\MissingActivationEvidenceException;
use App\Platform\FeatureGate\Models\FeatureGate;
use App\Platform\FeatureGate\Models\GateActivation;
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
 * NOT enforced here — both deferred by this batch's explicit brief, not
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
 * - AC9's outbox event emission — see `GateStateChanged`'s own doc block
 *   for exactly why and exactly where that write would go (marked TODO
 *   below).
 * - AC12's real audit trail integration. `Audit::record()` does not exist
 *   in this codebase yet (a sibling agent is building `app/Platform/Audit/**`
 *   in a worktree this batch cannot see). The exact call this method wants
 *   to make, once that API exists, is documented at the TODO below rather
 *   than approximated with a local duplicate audit mechanism.
 */
final readonly class GateActivationRecorder
{
    private const array VALID_STATES = ['open', 'closed'];

    public function record(
        string $gateId,
        int|string $actorReference,
        string $toState,
        string $evidenceReference,
        string $reason,
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

        return DB::transaction(function () use ($gateId, $actorReference, $toState, $evidenceReference, $reason): GateActivation {
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

            // TODO(AC9, blocked on S3-T11 outbox): write an `outbox_events`
            // row in THIS transaction, payload shaped like
            // `new GateStateChanged($gateId, $fromState, $toState,
            // $evidenceReference, $occurredAt->toIso8601String())` — see
            // that class's doc block. Not dispatched today.
            //
            // TODO(AC12, blocked on Audit module): once `Audit::record()`
            // exists, call it here (still inside this transaction) as
            // something shaped like:
            //   Audit::record(
            //       actor: $actorReference,
            //       action: 'feature_gate.state_changed',
            //       subject: ['type' => 'feature_gate', 'id' => $gateId],
            //       evidence: $evidenceReference,
            //       reason: $reason,
            //       occurredAt: $occurredAt,
            //   );
            // Not called today — no local substitute audit mechanism is
            // built in its place (see this batch's report).

            return $activation;
        });
    }
}
