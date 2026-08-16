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
}
