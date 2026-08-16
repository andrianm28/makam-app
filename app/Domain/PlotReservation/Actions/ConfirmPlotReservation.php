<?php

declare(strict_types=1);

namespace App\Domain\PlotReservation\Actions;

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
 * Sequencing — the same discipline as `ReservePlot`, documented there
 * at length, restated briefly here:
 * 1. `Audit::wrap()` (which opens the transaction) re-reads the latest
 *    row under `lockForUpdate()` — NOT the possibly-stale `$reservation`
 *    the caller passed in — so the state assert and the subsequent
 *    INSERT cannot be separated by a competing transition.
 * 2. Assert the re-read row's state is the allowed from-state (`held`);
 *    anything else — an already-confirmed, released, or expired row —
 *    throws `PlotReservationTransitionException::forTransition`.
 * 3. INSERT the new `confirmed` row (append-only; `from_state` is
 *    implied by the input's state, `to_state` the target, the cone
 *    `confirmed_at` timestamp, and the operator's `reason`).
 * 4. Emit `plot_reservation.state_changed.v1` via the transactional
 *    `Outbox` — the single catalogued plot-reservation event
 *    (`docs/contracts/event-catalog.md`), emitted with the same key
 *    shape `ReservePlot` uses.
 * 5. Sync the caller's instance to the new row so
 *    `$reservation->state` reflects the CONFIRMED hop.
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
                $current = PlotReservation::query()->lockForUpdate()->findOrFail($reservation->getKey());

                if ($current->state !== PlotReservationState::HELD) {
                    throw PlotReservationTransitionException::forTransition(
                        (string) $current->state,
                        PlotReservationState::CONFIRMED
                    );
                }

                $row = PlotReservation::query()->create([
                    'plot_id' => $current->plot_id,
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

                $this->emitStateChanged($row, (string) $current->getKey());

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
