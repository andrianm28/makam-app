<?php

declare(strict_types=1);

namespace App\Domain\PlotReservation\Actions;

use App\Domain\PlotInventory\Models\GravePlot;
use App\Domain\PlotInventory\PlotState;
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
 * reservation.md` — the `held` OR `confirmed` → `released` hop. Unlike
 * `ConfirmPlotReservation` (which keeps the claim), releasing RETURNS
 * the plot to `PlotState::AVAILABLE`: the reservation is no longer the
 * authoritative claim.
 *
 * ---------------------------------------------------------------------------
 * Lock discipline — see `ConfirmPlotReservation`'s class doc block
 * ---------------------------------------------------------------------------
 * The PLOT row lock is acquired FIRST (the shared mutable anchor
 * `ReservePlot` serializes on); the LATEST row of this plot's
 * reservation chain is then read and its state asserted against the
 * allowed from-states (`held` | `confirmed`). The plot flip is written
 * through the SAME locked re-read (`$plot`), so the INSERT and the
 * availability flip can never be separated by a competing transaction.
 */
final readonly class ReleasePlotReservation
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

                if (! $current instanceof PlotReservation || ! in_array($current->state, [PlotReservationState::HELD, PlotReservationState::CONFIRMED], true)) {
                    throw PlotReservationTransitionException::forTransition(
                        $current instanceof PlotReservation ? (string) $current->state : 'none',
                        PlotReservationState::RELEASED
                    );
                }

                $row = PlotReservation::query()->create([
                    'plot_id' => $plot->getKey(),
                    'order_id' => $current->order_id,
                    'state' => PlotReservationState::RELEASED,
                    'reserved_by_ref' => $current->reserved_by_ref,
                    'reserved_at' => $current->reserved_at,
                    'confirmed_at' => $current->confirmed_at,
                    'released_at' => now(),
                    'reason' => $reason,
                ]);

                $plot->update(['plot_state' => PlotState::AVAILABLE]);

                if ($reservation->getKey() !== $row->getKey()) {
                    $reservation->setRawAttributes($row->getAttributes(), true);
                }

                $this->emitStateChanged($row, (string) $plot->getKey(), (string) $current->state);

                return $row;
            },
            action: PlotReservationAuditActions::PLOT_RESERVATION_RELEASED,
            subject: fn (PlotReservation $row): AuditSubject => new AuditSubject('plot_reservation', $row->getKey()),
            outcome: AuditOutcome::Allowed,
            actorRef: $actorReference,
            actorRole: $actorRole,
            source: $auditSource,
            reason: $reason,
            correlationId: app(CorrelationContext::class)->current()?->value,
        );
    }

    private function emitStateChanged(PlotReservation $row, string $plotId, string $fromState): void
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
                'to_state' => PlotReservationState::RELEASED,
            ],
            classification: OutboxClassification::Internal,
            idempotencyKey: "plot_reservation:{$row->getKey()}",
        );
    }
}
