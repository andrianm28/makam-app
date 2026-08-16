<?php

declare(strict_types=1);

namespace App\Domain\Visitation;

/**
 * The audit action names this module records — the module-level
 * counterpart of `App\Domain\PlotReservation\PlotReservationAuditActions`.
 *
 * Deliberately NOT on `SensitiveActions::ACTIONS`: requesting a visit is
 * a customer self-service act, not a privileged/operator act — same
 * rationale the P3 plan's Global Constraints record for the
 * `PLOT_RESERVATION_*` constants. Later lanes (status transitions,
 * policy/blackout administration) add their own constants here.
 */
final class VisitationAuditActions
{
    public const string VISITATION_REQUESTED = 'VISITATION_REQUESTED';
}
