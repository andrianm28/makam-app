<?php

declare(strict_types=1);

namespace App\Domain\OrderWorkflow\Actions;

use App\Domain\CemeteryDirectory\PlotTrackingMode;
use App\Domain\OrderWorkflow\Models\Order;
use App\Domain\OrderWorkflow\Models\OrderStatusEvent;
use App\Domain\OrderWorkflow\OrderStatus;
use App\Domain\PlotReservation\Models\PlotReservation;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * TPU/TPS operator dashboard roadmap, Phase F
 * (`docs/superpowers/plans/2026-08-29-booking-flow-shortening.md`) — the
 * `DIVERIFIKASI -> PENAWARAN_TERKIRIM` shortcut: once a customer's plot
 * hold has converted onto this order's own `plot_reservations` chain
 * (Phase E, `ConvertDraftHoldToOrderReservation`), or an operator has
 * reserved a plot directly (`ReservePlotAction`), the manual
 * `MENUNGGU_KETERSEDIAAN` confirmation step is redundant — the plot is
 * already, verifiably, theirs.
 *
 * Deliberately a thin precondition wrapper around the EXISTING
 * `IssueOrderQuote`, not a reimplementation: quote composition, quote
 * issuance, and the actual `PENAWARAN_TERKIRIM` write all stay in exactly
 * one place. This class's only job is to refuse the shortcut when its
 * three preconditions are not all true, BEFORE `IssueOrderQuote` ever
 * runs — re-asserted here at call time, never trusted from the caller
 * (the same "the button was not rendered is not a security property"
 * discipline `ReservePlotAction`/`TransitionOrderAction` already follow;
 * this class is the domain-layer half of that pair, Task 2 of this plan
 * is the Filament-layer half).
 *
 * Precondition 1 — order status. `OrderTransition::ALLOWED['DIVERIFIKASI']`
 * now permits `PENAWARAN_TERKIRIM` (this plan's Task 1, same commit), but
 * that only makes the edge POSSIBLE — this class is what makes it
 * CONDITIONAL. An order anywhere else (already past DIVERIFIKASI, or not
 * yet verified) is refused here, before `IssueOrderQuote`'s own
 * `RecordOrderStatusChange` call would otherwise have to fail on its own
 * closed-set of allowed transitions.
 *
 * Precondition 2 — an active plot reservation.
 * `PlotReservation::activeForOrder($order)` returns non-null for BOTH
 * `held` and `confirmed` states (its `ACTIVE_STATES`) — matching the
 * roadmap's decision #6 verbatim ("a successfully-converted HELD
 * reservation is sufficient, no operator CONFIRMED gate required"). No
 * state-specific check is added here; a plain non-null read already
 * carries that decision.
 *
 * Precondition 3 — the reservation's own cemetery is granular-tier. This
 * is an EXPLICIT, ENFORCED check, deliberately not an assumption. An
 * earlier draft of this class asserted that aggregate-tier cemeteries
 * structurally cannot have `GravePlot` rows and therefore cannot reach
 * precondition 2 at all; that assertion was FALSE when this class was
 * written. `App\Domain\PlotInventory\Actions\CreateCemeteryBlock` did not
 * yet refuse a block on an aggregate-tier cemetery at that point (contrary
 * to `PlotTrackingMode::GRANULAR`'s own doc block); that gap was closed
 * shortly after, in a separate, already-merged PR
 * (`CreateCemeteryBlock::__invoke()` now guards it directly). This class's
 * own check is kept regardless, as defense-in-depth: it must not depend on
 * the other module's guard staying in place. Hence the tier is read from the
 * RESERVATION's own chain (`plot -> block -> cemetery`), not from the
 * order's booking draft, so that a divergent `booking_drafts.cemetery_id`
 * cannot decide a question about the plot actually being reserved. The
 * exclusion matters because an aggregate-tier cemetery's availability is
 * governed by `cemetery_packages.availability_status` capacity, which a
 * specific plot reservation is no evidence of — skipping the manual
 * `MENUNGGU_KETERSEDIAAN` confirmation there would skip the only check
 * that exists.
 */
final readonly class IssueQuoteFromReservedPlot
{
    public function __construct(private IssueOrderQuote $issueOrderQuote) {}

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __invoke(
        Order $order,
        CarbonInterface $expiresAt,
        string $actorRef,
        string $actorRole,
        ?string $reason = null,
        array $metadata = [],
    ): OrderStatusEvent {
        if ($order->status() !== OrderStatus::DIVERIFIKASI) {
            throw new InvalidArgumentException(
                'Order must be at DIVERIFIKASI to skip straight to a quote via a reserved plot; current status: '.$order->status()->value.'.'
            );
        }

        $reservation = PlotReservation::activeForOrder($order);

        if ($reservation === null) {
            throw new InvalidArgumentException(
                'Order has no active plot reservation to skip the availability step with.'
            );
        }

        if ($reservation->plot?->block?->cemetery?->plot_tracking_mode !== PlotTrackingMode::GRANULAR) {
            throw new InvalidArgumentException(
                "Order's reservation is not against a granular-tier cemetery; the availability shortcut only applies where a specific plot reservation is meaningful evidence of availability."
            );
        }

        return ($this->issueOrderQuote)($order, $expiresAt, $actorRef, $actorRole, $reason, $metadata);
    }
}
