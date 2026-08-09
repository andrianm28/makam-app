<?php

declare(strict_types=1);

namespace App\Domain\Booking\Actions;

use App\Domain\Booking\Models\BookingDraft;
use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditOutcome;
use App\Platform\Audit\AuditSource;
use App\Platform\Audit\AuditSubject;
use App\Platform\Outbox\Outbox;
use App\Platform\Outbox\OutboxClassification;
use Illuminate\Support\Facades\DB;

/**
 * Creates a new, empty `booking_drafts` row at step 1 — the ONLY way this
 * module creates a draft. `booking-wizard-fields.md` §Global behavior:
 * "Draft created at first meaningful input" — callers invoke this the
 * moment the visitor makes their first Step 1 selection, never eagerly on
 * page load (see `App\Livewire\Public\Booking\BookingWizard::mount()`,
 * Task 9).
 *
 * Audited via `Audit::record()`, wrapped in its own `DB::transaction()` so
 * the draft row and its audit event commit or roll back together — this
 * plan's Global Constraints ("every domain-layer write... calls
 * `Audit::record()` inside its own transaction") and the same precedent as
 * `App\Domain\ServiceCatalog\Actions\DefineServicePackage`. Not
 * `SensitiveActions`-listed.
 */
final readonly class StartBookingDraft
{
    public function __invoke(?int $userId = null): BookingDraft
    {
        return DB::transaction(function () use ($userId): BookingDraft {
            $draft = BookingDraft::create([
                'user_id' => $userId,
            ]);

            // `platform-outbox` AC1: the event and the state mutation commit
            // or roll back together. Inside the transaction this Action
            // already opened — deliberately not a second one.
            //
            // Event name: `docs/contracts/event-catalog.md` has no entry for
            // a booking-draft-started event. Its one booking row is
            // `booking.draft_submitted.v2`, a Step 9 SUBMISSION event, and
            // Step 9 is unbuilt (`BookingWizardStep::LAST_IMPLEMENTED` is 5).
            // Per AC3 this Action does not invent a catalogue entry; it emits
            // a clearly-provisional name following the catalogue's own
            // `noun.verb_past_tense.vN` convention, and the gap is recorded
            // as finding N-17 in `docs/planning/sprint-plan.md` — the same
            // disclosed-gap treatment finding N-12 already applied to
            // `feature_gate.state_changed.v1`.
            Outbox::record(
                eventName: 'booking.draft_started.v1',
                eventVersion: 1,
                aggregateType: 'booking_draft',
                aggregateId: $draft->id,
                data: [
                    'draft_id' => $draft->id,
                    // A role, never an identifier. AC2 is references-only and
                    // `outbox_events` has no actor columns by design (finding
                    // N-11) — `user_id` is deliberately absent.
                    'actor_role' => $userId !== null ? 'customer' : 'guest',
                    'started_at' => $draft->created_at->toIso8601String(),
                ],
                classification: OutboxClassification::Internal,
                // One draft can only be started once, so the aggregate id is
                // itself the natural uniqueness key here — unlike
                // `GateActivationRecorder`, where the same gate legitimately
                // changes state many times and the key had to be per-activation.
                idempotencyKey: "booking_draft:{$draft->id}:started",
            );

            Audit::record(
                action: 'BOOKING_DRAFT_STARTED',
                subject: new AuditSubject('booking_draft', $draft->id, $draft->version),
                outcome: AuditOutcome::Allowed,
                actorRef: $userId,
                actorRole: $userId !== null ? 'customer' : 'guest',
                source: AuditSource::Api,
            );

            return $draft;
        });
    }
}
