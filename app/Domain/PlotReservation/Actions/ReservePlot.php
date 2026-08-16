<?php

declare(strict_types=1);

namespace App\Domain\PlotReservation\Actions;

use App\Domain\OrderWorkflow\Models\Order;
use App\Domain\PlotInventory\Models\GravePlot;
use App\Domain\PlotInventory\PlotState;
use App\Domain\PlotReservation\Exceptions\PlotNotAvailableException;
use App\Domain\PlotReservation\Exceptions\PlotReservationConflictException;
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
use Illuminate\Database\QueryException;

/**
 * Task 3 of `docs/superpowers/plans/2026-08-16-p3-plot-inventory-
 * reservation.md` — the atomic claim of a specific plot for an order:
 * one active `held` reservation per plot, per order.
 *
 * ---------------------------------------------------------------------------
 * Sequencing inside the transaction, and why
 * ---------------------------------------------------------------------------
 * 1. Order-level idempotency pre-check OUTSIDE the transaction (the
 *    `SubmitBookingDraft` fast-path shape): an order with an active
 *    reservation gets its incumbent back. This is a courtesy fast path —
 *    the common duplicate is a double-tap seconds apart, and it costs one
 *    SELECT instead of a lock, an INSERT, a rollback, and a SELECT. It is
 *    NOT the correctness mechanism: the plot-level check below is what
 *    actually refuses a second reservation, and the partial unique index
 *    backstops it at the database.
 * 2. `Audit::wrap()` (which opens the transaction) runs the mutation:
 *    a. Re-read the plot with `lockForUpdate()` INSIDE the transaction —
 *       not the possibly-stale `$plot` the caller passed in. Two
 *       concurrent callers racing the same plot each block on this row
 *       lock; the first to commit wins, and the second's re-read then
 *       sees `plot_state = reserved`, so its own availability assert is
 *       what actually rejects it (`plot_reservations_active_hold`, the
 *       partial unique index, is the second, database-level backstop for
 *       the one narrow window in which both callers pass the assert
 *       before either commits).
 *    b. Assert `plot_state === available` — otherwise
 *       `PlotNotAvailableException::forPlot`. Read against the LOCKED
 *       row, so the assert and the subsequent write cannot be separated
 *       by a competing transaction.
 *    c. Insert the `held` row (the append-only event record; `create()`
 *       is deliberately the one unguarded write path — see the model's
 *       class doc block).
 *    d. Flip the plot to `reserved` — the plot's state mirrors the
 *       latest active reservation. The write goes through the LOCKED
 *       re-read (`$current`), not the caller's instance, and the
 *       caller's instance is then synced — the same discipline
 *       `RecordOrderStatusChange::record()` documents at length: the
 *       obvious next line (`if ($plot->plot_state === ...)`) reads stale
 *       state otherwise.
 *    e. Emit `plot_reservation.state_changed.v1` via the transactional
 *       `Outbox` — the single catalogued plot-reservation event
 *       (`docs/contracts/event-catalog.md`); no new event name is
 *       invented (Global Constraint N-12).
 *    `Audit::wrap()` writes the `PLOT_RESERVATION_CREATED` audit row in
 *    the same transaction, so mutation, audit, and outbox row can never
 *    be committed separately (AC4).
 * 3. A `QueryException` matching the narrow duplicate-hold classifier is
 *    translated into `PlotReservationConflictException`; anything else
 *    propagates untouched.
 *
 * ---------------------------------------------------------------------------
 * `activeForOrder` vs the two-connection race test
 * ---------------------------------------------------------------------------
 * The order-level pre-check means a duplicate attempt by the SAME order
 * returns the incumbent — it never reaches the plot-level backstop.
 * `ReservePlotTwoConnectionTest` therefore drives its second session
 * with a DIFFERENT order, so the plot-level assert is what refuses it.
 *
 * ---------------------------------------------------------------------------
 * Idempotency is order-scoped, availability is plot-scoped
 * ---------------------------------------------------------------------------
 * One order can hold at most one plot, and one plot can be held by at
 * most one order — each guarantee enforced by a different mechanism
 * (the pre-check for the first, the lock + assert + partial unique index
 * for the second). The two are deliberately not conflated.
 */
final readonly class ReservePlot
{
    public function __invoke(
        GravePlot $plot,
        Order $order,
        int|string $actorReference,
        string $actorRole,
        ?string $reason = null,
        AuditSource $auditSource = AuditSource::Panel,
    ): PlotReservation {
        // Step 1 — outside the transaction: a duplicate attempt by the
        // same order costs one SELECT and returns the incumbent (see the
        // class doc block).
        $incumbent = PlotReservation::activeForOrder($order);

        if ($incumbent instanceof PlotReservation) {
            return $incumbent;
        }

        try {
            return Audit::wrap(
                mutation: function () use ($plot, $order, $actorReference, $reason): PlotReservation {
                    $current = GravePlot::query()->lockForUpdate()->findOrFail($plot->getKey());

                    if ($current->plot_state !== PlotState::AVAILABLE) {
                        throw PlotNotAvailableException::forPlot((string) $current->getKey());
                    }

                    $row = PlotReservation::query()->create([
                        'plot_id' => $current->getKey(),
                        'order_id' => $order->getKey(),
                        'state' => PlotReservationState::HELD,
                        'reserved_by_ref' => (string) $actorReference,
                        'reserved_at' => now(),
                        'reason' => $reason,
                    ]);

                    $current->update(['plot_state' => PlotState::RESERVED]);

                    if ($plot !== $current) {
                        $plot->setRawAttributes($current->getAttributes(), true);
                    }

                    $this->emitStateChanged($row, (string) $current->getKey());

                    return $row;
                },
                action: PlotReservationAuditActions::PLOT_RESERVATION_CREATED,
                subject: fn (PlotReservation $row): AuditSubject => new AuditSubject('plot_reservation', $row->getKey()),
                outcome: AuditOutcome::Allowed,
                actorRef: $actorReference,
                actorRole: $actorRole,
                source: $auditSource,
                reason: $reason,
                // `AGENTS.md` §Observability: "Preserve trace/request IDs
                // across request, outbox, queue, provider, and notification
                // flows." Same read `RecordOrderStatusChange` makes, so the
                // audit row and the outbox row (which `Outbox::record()`
                // reads this context for itself) share the trace id.
                correlationId: app(CorrelationContext::class)->current()?->value,
            );
        } catch (QueryException $exception) {
            if (! $this->isDuplicateActiveHold($exception)) {
                throw $exception;
            }

            // Deliberately not chained as `$previous` — see
            // `PlotReservationConflictException`'s doc block: the original
            // message carries the interpolated `reserved_by_ref`/`reason`
            // bindings.
            throw PlotReservationConflictException::forPlot((string) $plot->getKey());
        }
    }

    /**
     * `event-catalog.md` — the single catalogued plot-reservation event,
     * emitted from exactly one place so the reservation actions cannot
     * drift into two payload shapes or, worse, two event names.
     *
     * References only: reservation id and plot id, never reservation or
     * plot content. `from_state` is null for the creation event and is
     * carried through as a JSON null rather than omitted, so a consumer
     * can tell "this hold was created" from "someone forgot to send the
     * previous state".
     */
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

    /**
     * Same narrow-classifier discipline as
     * `RecordOrderStatusChange::isDuplicatePaidEvent()` and
     * `SubmitBookingDraft::isDuplicateIdempotencyKey()`, and narrow for
     * the reason those document at length: a `QueryException`'s message
     * echoes the INSERT's own column list, so matching a BARE column name
     * would classify a NOT NULL or length violation on this table as a
     * duplicate hold.
     *
     * PostgreSQL names the failing index directly
     * (`plot_reservations_active_hold`) and is matched first. SQLite
     * reports the QUALIFIED `plot_reservations.plot_id` form, which
     * appears only in its constraint description and never in the
     * unqualified INSERT column list, and pairs it with the word
     * "unique" — so both signals are required on that branch, exactly as
     * the `order_status_events` precedent verified against this
     * repository's SQLite test driver.
     */
    private function isDuplicateActiveHold(QueryException $exception): bool
    {
        $message = strtolower($exception->getMessage());

        if (str_contains($message, 'plot_reservations_active_hold')) {
            return true;
        }

        return str_contains($message, 'unique')
            && str_contains($message, 'plot_reservations.plot_id');
    }
}
