<?php

declare(strict_types=1);

namespace App\Domain\PlotReservation\Actions;

use App\Domain\Booking\Models\BookingDraft;
use App\Domain\PlotInventory\Models\GravePlot;
use App\Domain\PlotInventory\PlotState;
use App\Domain\PlotReservation\Exceptions\PlotNotAvailableException;
use App\Domain\PlotReservation\Exceptions\PlotReservationTransitionException;
use App\Domain\PlotReservation\Models\PlotReservation;
use App\Domain\PlotReservation\PlotReservationAuditActions;
use App\Domain\PlotReservation\PlotReservationState;
use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditOutcome;
use App\Platform\Audit\AuditSource;
use App\Platform\Audit\AuditSubject;
use App\Platform\Correlation\CorrelationContext;
use App\Platform\Outbox\Outbox;
use App\Platform\Outbox\OutboxClassification;
use Illuminate\Support\Facades\DB;

/**
 * Phase E (`docs/superpowers/plans/2026-08-29-customer-plot-picker-hold.md`
 * Task 2) — the draft-scoped mirror of `ReservePlot`: the same lock
 * discipline (order/draft-row lock first, then the plot-row lock, then the
 * availability assert against the LOCKED row), applied to a `BookingDraft`
 * instead of an `Order`, because a real `Order` does not exist until Step 8
 * (`SubmitBookingDraft`) but the picker needs to claim a plot at Step 2.
 *
 * Every structural decision below is inherited unchanged from `ReservePlot`
 * — see that class's own doc block for the full "why" (the rejected
 * partial-unique-index backstop, the plot-row-lock-is-the-serialization-
 * anchor reasoning, the append-only "one row per transition" shape,
 * finding I1's order-row-lock-first discipline). Only two things differ:
 *
 * 1. The lock/idempotency anchor is `booking_draft_id`, not `order_id` —
 *    but the DISCIPLINE is identical: `BookingDraft::query()->
 *    lockForUpdate()` is taken FIRST, inside the transaction, exactly the
 *    way `ReservePlot` locks `Order::query()` first (step 2a there). A
 *    `BookingDraft` row is just as lockable as an `Order` row — nothing
 *    about `SaveBookingDraftStep`'s own optimistic-version check on the
 *    wizard's save path stops this action from also taking a real row
 *    lock here. Without it, finding I1's exact race reappears one layer
 *    down: a customer double-clicking two DIFFERENT plot cells in the
 *    same draft could have both calls pass the outside-the-transaction
 *    incumbent pre-check before either commits, each lock a DIFFERENT
 *    plot row (no contention between them), and both commit — the same
 *    draft ending up with two simultaneous active holds, which must be
 *    impossible for exactly the reason it must be impossible for an
 *    order. Locking the draft row first serializes that race the same
 *    way locking the order row does for `ReservePlot`.
 * 2. A TTL: `expires_at` is set from `$ttlMinutes` (explicit) or
 *    `config('plot-reservation.draft_hold_ttl_minutes')` (default) — an
 *    order-anchored hold never expires on a timer, a draft-anchored one
 *    always does, because the customer may simply abandon the tab.
 *
 * ---------------------------------------------------------------------------
 * Switching plots vs. repeating the same request (whole-branch review C2)
 * ---------------------------------------------------------------------------
 * The incumbent fast path is keyed on the REQUESTED plot, not merely on
 * "this draft has some hold". The wizard's stepper lets a customer walk
 * back into Step 2 once it is in `completed_steps` and pick again — and
 * an incumbent check that ignored which plot was asked for would silently
 * return the OLD hold, letting the draft's saved `cemetery_id` move on
 * (`BookingWizard::saveStep2()` still runs) while the actual reservation
 * stayed anchored to a plot in the cemetery the customer just left. The
 * order would then ship with the wrong plot.
 *
 * So there are two distinct cases, and only one of them is idempotent:
 *
 * - SAME plot re-requested — a double-tap, a retried Livewire round-trip,
 *   a wizard resume re-rendering the picker. Return the incumbent
 *   unchanged. No release/recreate churn, no second audit row.
 * - DIFFERENT plot requested — a genuine change of mind. Honour the most
 *   recent choice: release the old hold through `ReleasePlotReservation`
 *   (which returns the abandoned plot to `available` and appends its own
 *   `released` row and audit trail) and then create the new hold, all
 *   under the same draft lock, so the draft can never be observed holding
 *   two plots. Blocking instead would be worse than the bug: the customer
 *   has no way to release their own hold and would be stuck until the TTL
 *   expired.
 *
 * This is the customer-facing mirror of the operator-side guard in
 * `ReservePlotAction::visibleFor()` (`activeForOrder($order) !== null`),
 * which hides the operator's Reserve action outright — an operator has
 * the explicit Release control to recover with, a public visitor does not,
 * which is why the resolution differs.
 *
 * The cemetery boundary is deliberately NOT checked here: this action
 * knows about plots and drafts, not about which cemetery the wizard has
 * saved. A cross-cemetery re-pick is just a different plot, handled by the
 * same release-then-hold path, and keeping the draft's `cemetery_id` in
 * step is `saveStep2()`'s job.
 */
final readonly class HoldPlotForDraft
{
    public function __invoke(
        GravePlot $plot,
        BookingDraft $draft,
        int|string $actorReference,
        ?int $ttlMinutes = null,
        ?string $reason = null,
        AuditSource $auditSource = AuditSource::Api,
    ): PlotReservation {
        // Step 1 — outside the transaction: a duplicate attempt by the same
        // draft for the SAME plot (a double-tap, or a wizard resume that
        // re-renders the picker) costs one SELECT and returns the
        // incumbent. See `ReservePlot`'s class doc block for why this is a
        // courtesy fast path, not the correctness mechanism. An incumbent
        // on a DIFFERENT plot deliberately does NOT short-circuit — that
        // is a plot switch, and it is resolved under the lock below.
        $incumbent = PlotReservation::activeForDraft($draft);

        if ($incumbent instanceof PlotReservation && $incumbent->plot_id === $plot->getKey()) {
            return $incumbent;
        }

        $ttl = $ttlMinutes ?? (int) config('plot-reservation.draft_hold_ttl_minutes');

        return DB::transaction(function () use (
            $plot,
            $draft,
            $actorReference,
            $auditSource,
            $reason,
            $ttl,
        ): PlotReservation {
            // Step 2a — the DRAFT-row lock first, then the authoritative
            // incumbent re-check (mirrors `ReservePlot`'s finding-I1 fix
            // exactly, see class doc block point 1): serializes two
            // concurrent holds by the SAME draft against two DIFFERENT
            // plots before either reaches a plot row.
            $lockedDraft = BookingDraft::query()->lockForUpdate()->findOrFail($draft->getKey());

            $incumbent = PlotReservation::activeForDraft($lockedDraft);

            if ($incumbent instanceof PlotReservation) {
                if ($incumbent->plot_id === $plot->getKey()) {
                    return $incumbent;
                }

                // A plot switch (class doc block). Release the abandoned
                // hold and fall through to claim the newly chosen plot —
                // never return here.
                try {
                    (new ReleasePlotReservation)(
                        $incumbent,
                        $actorReference,
                        'customer',
                        reason: 'customer selected a different plot',
                        auditSource: $auditSource,
                    );
                } catch (PlotReservationTransitionException) {
                    // The old hold stopped being its plot's live head
                    // between the re-derivation above and this write — the
                    // expiry sweep locks the plot row, not the draft row,
                    // so it can win that narrow race. The old hold is
                    // closed either way, which is exactly the state this
                    // release was trying to reach, so continue.
                }
            }

            // Step 2b — the plot-row lock: the shared mutable anchor,
            // exactly as in `ReservePlot`.
            $current = GravePlot::query()->lockForUpdate()->findOrFail($plot->getKey());

            if ($current->plot_state !== PlotState::AVAILABLE) {
                throw PlotNotAvailableException::forPlot((string) $current->getKey());
            }

            $row = PlotReservation::query()->create([
                'plot_id' => $current->getKey(),
                'booking_draft_id' => $lockedDraft->getKey(),
                'state' => PlotReservationState::HELD,
                'reserved_by_ref' => (string) $actorReference,
                'reserved_at' => now(),
                'expires_at' => now()->addMinutes($ttl),
                'reason' => $reason,
            ]);

            $current->update(['plot_state' => PlotState::RESERVED]);

            if ($plot !== $current) {
                $plot->setRawAttributes($current->getAttributes(), true);
            }

            $this->emitStateChanged($row, (string) $current->getKey());

            Audit::record(
                action: PlotReservationAuditActions::PLOT_RESERVATION_CREATED,
                subject: new AuditSubject('plot_reservation', $row->getKey()),
                outcome: AuditOutcome::Allowed,
                actorRef: $actorReference,
                actorRole: 'customer',
                source: $auditSource,
                reason: $reason,
                correlationId: app(CorrelationContext::class)->current()?->value,
            );

            return $row;
        });
    }

    private function emitStateChanged(PlotReservation $row, string $plotId): void
    {
        Outbox::record(
            eventName: 'plot_reservation.state_changed.v1',
            eventVersion: 1,
            aggregateType: 'plot_reservation',
            aggregateId: (string) $row->getKey(),
            data: [
                'reservation_id' => (string) $row->getKey(),
                'plot_id' => $plotId,
                'from_state' => null,
                'to_state' => PlotReservationState::HELD,
            ],
            classification: OutboxClassification::Internal,
            idempotencyKey: "plot_reservation:{$row->getKey()}",
        );
    }
}
