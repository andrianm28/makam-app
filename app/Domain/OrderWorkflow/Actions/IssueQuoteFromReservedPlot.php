<?php

declare(strict_types=1);

namespace App\Domain\OrderWorkflow\Actions;

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
 * two preconditions are not both true, BEFORE `IssueOrderQuote` ever
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
 * carries that decision. An aggregate-tier cemetery never has `GravePlot`
 * rows at all, so this is always null for such an order — the shortcut is
 * structurally unreachable for aggregate-tier orders, not specially
 * excluded.
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

        if (PlotReservation::activeForOrder($order) === null) {
            throw new InvalidArgumentException(
                'Order has no active plot reservation to skip the availability step with.'
            );
        }

        return ($this->issueOrderQuote)($order, $expiresAt, $actorRef, $actorRole, $reason, $metadata);
    }
}
