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
use Illuminate\Support\Facades\DB;

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
 *
 * ---------------------------------------------------------------------------
 * The override-divergence rule (whole-branch review finding C1)
 * ---------------------------------------------------------------------------
 * The availability flip is CONDITIONAL: it happens only while the
 * locked plot's state is `PlotState::RESERVED`. An admin override
 * ('Tandai Terisi' → occupied, 'Tandai Perawatan' → maintenance — see
 * `GravePlotsTable`) on a reserved plot changes the plot's state behind
 * the reservation chain, and releasing must not silently destroy that
 * override by flipping an occupied/maintenance plot back to `available`
 * (a buried plot becoming reservable). When the state under the lock is
 * NOT `reserved`, the chain is still closed (the `released` row is
 * appended — the reservation is over either way) but `plot_state` is
 * left untouched, and the divergence is recorded in the action's audit
 * reason so the trail explains why the plot stayed occupied/maintenance.
 *
 * This is why the action writes its own audit row with
 * `Audit::record()` inside the transaction instead of using
 * `Audit::wrap()`: the divergence is only knowable under the plot lock,
 * and `Audit::wrap()`'s `$reason` is fixed at call time. `Audit::record`
 * inside `DB::transaction` is the documented alternative shape
 * ("same transaction some other way" — `Audit`'s class doc block), and
 * keeps mutation, audit, and outbox row atomic (AC4).
 *
 * `booking_draft_id` is carried forward from `$current` exactly like
 * `order_id`, so a released row never silently drops which draft it
 * belongs to.
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
        return DB::transaction(function () use (
            $reservation,
            $actorReference,
            $actorRole,
            $auditSource,
            $reason,
        ): PlotReservation {
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
                'booking_draft_id' => $current->booking_draft_id,
                'state' => PlotReservationState::RELEASED,
                'reserved_by_ref' => $current->reserved_by_ref,
                'reserved_at' => $current->reserved_at,
                'confirmed_at' => $current->confirmed_at,
                'released_at' => now(),
                'reason' => $reason,
            ]);

            $diverged = $plot->plot_state !== PlotState::RESERVED;

            if (! $diverged) {
                $plot->update(['plot_state' => PlotState::AVAILABLE]);
            }

            if ($reservation->getKey() !== $row->getKey()) {
                $reservation->setRawAttributes($row->getAttributes(), true);
            }

            $this->emitStateChanged($row, (string) $plot->getKey(), (string) $current->state);

            Audit::record(
                action: PlotReservationAuditActions::PLOT_RESERVATION_RELEASED,
                subject: new AuditSubject('plot_reservation', $row->getKey()),
                outcome: AuditOutcome::Allowed,
                actorRef: $actorReference,
                actorRole: $actorRole,
                source: $auditSource,
                reason: self::reasonWithDivergence($reason, $diverged),
                correlationId: app(CorrelationContext::class)->current()?->value,
            );

            return $row;
        });
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

    /**
     * The override-divergence note appended to the audit `reason` when
     * the chain was closed without touching the plot (finding C1): the
     * trail must record WHY the plot did not return to `available`, or
     * a later reviewer would read the released chain and the
     * occupied/maintenance plot as a broken flip.
     */
    private static function reasonWithDivergence(?string $reason, bool $diverged): ?string
    {
        if (! $diverged) {
            return $reason;
        }

        $suffix = 'plot state diverged from reserved (override preserved)';

        return $reason === null ? $suffix : $reason.'; '.$suffix;
    }
}
