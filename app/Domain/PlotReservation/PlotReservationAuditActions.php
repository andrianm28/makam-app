<?php

declare(strict_types=1);

namespace App\Domain\PlotReservation;

/**
 * The audit action names this module records — the module-level
 * counterpart of `App\Domain\OrderWorkflow\OrderWorkflowAuditActions`.
 *
 * Deliberately NOT on `SensitiveActions::ACTIONS`: reservation work is
 * machine/operator routine, not a money-adjacent privileged act — same
 * rationale the plan's Global Constraints record for all four
 * `PLOT_RESERVATION_*` constants ("none on SensitiveActions::ACTIONS
 * (machine/operator routine, same rationale as the marketplace
 * constants)").
 */
final class PlotReservationAuditActions
{
    public const string PLOT_RESERVATION_CREATED = 'PLOT_RESERVATION_CREATED';

    public const string PLOT_RESERVATION_CONFIRMED = 'PLOT_RESERVATION_CONFIRMED';

    public const string PLOT_RESERVATION_RELEASED = 'PLOT_RESERVATION_RELEASED';

    public const string PLOT_RESERVATION_EXPIRED = 'PLOT_RESERVATION_EXPIRED';

    /**
     * Batch M3b (DOM-08): the paid-order override variants, written by the
     * SAME `ReleasePlotReservation`/`ExpirePlotReservation` actions instead
     * of the plain constants above whenever `overridePaidOrder: true` was
     * used to release/expire a reservation whose owning order is already
     * `DIBAYAR` or later. Deliberately distinct action names — not a reuse
     * of `PLOT_RESERVATION_RELEASED`/`PLOT_RESERVATION_EXPIRED` — so the
     * audit trail can tell an ordinary pre-payment release apart from an
     * override on a paid order. UNLIKE the four constants above, these two
     * ARE on `SensitiveActions::ACTIONS`: overriding a paid order's
     * reservation is a human-initiated, money-adjacent privileged act (the
     * same category `PAYMENT_REFUND`/`RENEWAL_EXTERNAL_MARKING` are), not
     * machine/operator routine.
     */
    public const string PLOT_RESERVATION_RELEASED_PAID_ORDER_OVERRIDE = 'PLOT_RESERVATION_RELEASED_PAID_ORDER_OVERRIDE';

    public const string PLOT_RESERVATION_EXPIRED_PAID_ORDER_OVERRIDE = 'PLOT_RESERVATION_EXPIRED_PAID_ORDER_OVERRIDE';
}
