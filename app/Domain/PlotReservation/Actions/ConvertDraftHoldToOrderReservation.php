<?php

declare(strict_types=1);

namespace App\Domain\PlotReservation\Actions;

use App\Domain\OrderWorkflow\Models\Order;
use App\Domain\PlotInventory\Models\GravePlot;
use App\Domain\PlotReservation\Exceptions\DraftPlotHoldNoLongerValidException;
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
 * Task 3) — re-anchors a customer's draft-scoped plot hold onto the real
 * `Order` `SubmitBookingDraft` just created, inside that same transaction.
 *
 * Called ONLY from `SubmitBookingDraft::submit()`, after the order row
 * exists (an order id is required to anchor the new row) and only when
 * `PlotReservation::activeForDraft($draft)` is non-null — a draft that
 * never went through the picker (aggregate-tier cemetery, or a plan
 * shipped before this feature) has no hold to convert, and this action is
 * never called for it.
 *
 * ---------------------------------------------------------------------------
 * Why a NEW row, not an update to the draft hold
 * ---------------------------------------------------------------------------
 * `PlotReservation` is append-only (`update()`/`delete()` throw — see the
 * model's class doc block). The draft hold's chain is closed with a
 * `converted` row (still referencing `booking_draft_id`, so the chain's own
 * history stays intact), and a SEPARATE, NEW row is appended anchored to
 * `order_id` instead, starting at `held` — the same state `ReservePlot`
 * would have produced had the operator claimed this plot directly. This is
 * why `TransitionOrderAction`'s later `PENAWARAN_TERKIRIM` shortcut (Phase
 * F) can treat "a converted `HELD` reservation" as sufficient per the
 * roadmap's decision #6: from the order's perspective, its `plot_reservations`
 * chain looks exactly like ANY order-anchored `held` reservation.
 *
 * ---------------------------------------------------------------------------
 * Lock discipline and the no-fallback failure mode
 * ---------------------------------------------------------------------------
 * The plot row is RE-LOCKED (not trusted from the caller's possibly-stale
 * `$draftHold->plot`), and the LATEST row of the PLOT's own chain (not the
 * draft's) is re-read under that lock — the same "re-derive from the
 * locked row, not the argument" discipline every other action in this
 * module follows. Conversion succeeds only if that latest row IS
 * `$draftHold` itself, still `held`, and its `expires_at` has not passed.
 * Any other outcome — already converted, expired, or (in a genuine race)
 * lost to a concurrent expiry sweep — throws
 * `DraftPlotHoldNoLongerValidException` and the WHOLE transaction rolls
 * back (per the roadmap's decision #7: no silent fallback to submitting
 * without a reservation; the wizard blocks and sends the customer back to
 * re-pick).
 */
final readonly class ConvertDraftHoldToOrderReservation
{
    public function __invoke(
        PlotReservation $draftHold,
        Order $order,
        AuditSource $auditSource = AuditSource::Api,
    ): PlotReservation {
        return DB::transaction(function () use ($draftHold, $order, $auditSource): PlotReservation {
            $plot = GravePlot::query()->lockForUpdate()->findOrFail($draftHold->plot_id);

            $current = PlotReservation::query()
                ->where('plot_id', $plot->getKey())
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->first();

            if (
                ! $current instanceof PlotReservation
                || $current->getKey() !== $draftHold->getKey()
                || $current->state !== PlotReservationState::HELD
            ) {
                throw DraftPlotHoldNoLongerValidException::forHold(
                    (string) $draftHold->getKey(),
                    'no longer the live head of this plot\'s reservation chain'
                );
            }

            if ($current->expires_at !== null && $current->expires_at->isPast()) {
                throw DraftPlotHoldNoLongerValidException::forHold((string) $draftHold->getKey(), 'hold has expired');
            }

            $actorRef = "booking_draft:{$current->booking_draft_id}";

            $closing = PlotReservation::query()->create([
                'plot_id' => $plot->getKey(),
                'booking_draft_id' => $current->booking_draft_id,
                'order_id' => null,
                'state' => PlotReservationState::CONVERTED,
                'reserved_by_ref' => $current->reserved_by_ref,
                'reserved_at' => $current->reserved_at,
                'reason' => 'converted to order reservation on submission',
            ]);

            $reanchored = PlotReservation::query()->create([
                'plot_id' => $plot->getKey(),
                'order_id' => $order->getKey(),
                'state' => PlotReservationState::HELD,
                'reserved_by_ref' => $actorRef,
                'reserved_at' => now(),
            ]);

            // The plot was already `reserved` for the draft hold; it stays
            // `reserved` — the claim just moved to a new anchor. No plot
            // write needed here, unlike `ReservePlot`'s creation path.

            $this->emitStateChanged($closing, (string) $plot->getKey(), PlotReservationState::HELD, PlotReservationState::CONVERTED);
            $this->emitStateChanged($reanchored, (string) $plot->getKey(), null, PlotReservationState::HELD);

            Audit::record(
                action: PlotReservationAuditActions::PLOT_RESERVATION_CREATED,
                subject: new AuditSubject('plot_reservation', $reanchored->getKey()),
                outcome: AuditOutcome::Allowed,
                actorRef: $actorRef,
                actorRole: 'customer',
                source: $auditSource,
                reason: "converted from draft hold {$current->getKey()} on order {$order->getKey()}",
                correlationId: app(CorrelationContext::class)->current()?->value,
            );

            return $reanchored;
        });
    }

    private function emitStateChanged(PlotReservation $row, string $plotId, ?string $fromState, string $toState): void
    {
        Outbox::record(
            eventName: 'plot_reservation.state_changed.v1',
            eventVersion: 1,
            aggregateType: 'plot_reservation',
            aggregateId: (string) $row->getKey(),
            data: [
                'reservation_id' => (string) $row->getKey(),
                'plot_id' => $plotId,
                'from_state' => $fromState,
                'to_state' => $toState,
            ],
            classification: OutboxClassification::Internal,
            idempotencyKey: "plot_reservation:{$row->getKey()}",
        );
    }
}
