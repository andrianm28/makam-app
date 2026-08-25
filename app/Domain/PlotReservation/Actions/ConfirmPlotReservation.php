<?php

declare(strict_types=1);

namespace App\Domain\PlotReservation\Actions;

use App\Domain\PlotInventory\Models\GravePlot;
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

/**
 * Task 4 of `docs/superpowers/plans/2026-08-16-p3-plot-inventory-
 * reservation.md` — the `held` → `confirmed` hop of the append-only
 * reservation state machine. The plot STAYS `reserved`: a confirmed
 * reservation is still the authoritative claim on the plot, so no
 * `grave_plots` write happens (only the release/expire hops return the
 * plot to `available`).
 *
 * ---------------------------------------------------------------------------
 * Lock discipline — WHY the PLOT row, not the reservation row
 * ---------------------------------------------------------------------------
 * The reservation rows are append-only and immutable, so locking the
 * reservation row the caller handed in serializes nobody: two
 * concurrent transitions on the same held chain (confirm racing
 * expire) would both re-read `held` by id, both pass the assert, and
 * both commit — forking the chain and leaving the plot double-claimed
 * (one caller's flip to `available` racing another's hold).
 *
 * The shared mutable anchor is the PLOT row — the row `ReservePlot`
 * already serializes on. Every lifecycle transition therefore takes
 * `GravePlot::query()->lockForUpdate()` FIRST, then reads the LATEST
 * row of this plot's reservation chain and asserts its state is the
 * allowed from-state. Competing transitions on the same plot block on
 * the plot lock; the loser's chain re-read sees the winner's committed
 * row and throws `PlotReservationTransitionException`. The caller's
 * `$reservation` instance is used ONLY to derive the plot id (its
 * `plot_id` is immutable — append-only rows never change it).
 *
 * `ConfirmPlotReservation` takes the plot lock too even though it
 * writes no plot row: serialization is what makes the state assert
 * meaningful, and the outbox payload's `plot_id` comes from the locked
 * re-read like every other hop.
 */
final readonly class ConfirmPlotReservation
{
    public function __invoke(
        PlotReservation $reservation,
        int|string $actorReference,
        string $actorRole,
        ?string $reason = null,
        AuditSource $auditSource = AuditSource::Panel,
    ): PlotReservation {
        return Audit::wrap(
            mutation: function () use ($reservation, $reason): PlotReservation {
                $plot = GravePlot::query()->lockForUpdate()->findOrFail($reservation->plot_id);

                $current = PlotReservation::query()
                    ->where('plot_id', $plot->getKey())
                    ->orderByDesc('created_at')
                    ->orderByDesc('id')
                    ->first();

                if (! $current instanceof PlotReservation || $current->state !== PlotReservationState::HELD) {
                    throw PlotReservationTransitionException::forTransition(
                        $current instanceof PlotReservation ? (string) $current->state : 'none',
                        PlotReservationState::CONFIRMED
                    );
                }

                $row = PlotReservation::query()->create([
                    'plot_id' => $plot->getKey(),
                    'order_id' => $current->order_id,
                    'state' => PlotReservationState::CONFIRMED,
                    'reserved_by_ref' => $current->reserved_by_ref,
                    'reserved_at' => $current->reserved_at,
                    'confirmed_at' => now(),
                    'reason' => $reason,
                ]);

                // The plot stays `reserved` — no `grave_plots` write.

                if ($reservation->getKey() !== $row->getKey()) {
                    $reservation->setRawAttributes($row->getAttributes(), true);
                }

                $this->emitStateChanged($row, (string) $plot->getKey());

                return $row;
            },
            action: PlotReservationAuditActions::PLOT_RESERVATION_CONFIRMED,
            subject: fn (PlotReservation $row): AuditSubject => new AuditSubject('plot_reservation', $row->getKey()),
            outcome: AuditOutcome::Allowed,
            actorRef: $actorReference,
            actorRole: $actorRole,
            source: $auditSource,
            reason: $reason,
            correlationId: app(CorrelationContext::class)->current()?->value,
        );
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
                'from_state' => PlotReservationState::HELD,
                'to_state' => PlotReservationState::CONFIRMED,
            ],
            classification: OutboxClassification::Internal,
            idempotencyKey: "plot_reservation:{$row->getKey()}",
        );
    }
}
