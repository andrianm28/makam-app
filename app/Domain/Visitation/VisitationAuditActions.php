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
 * `PLOT_RESERVATION_*` constants. The same reasoning covers the Lane 2
 * additions below: an operator confirming/cancelling a visit is routine
 * queue work (unlike a plot override, which changes a grave's ownership-
 * relevant inventory state), a policy update is operator configuration of
 * an informational feature, and a blackout date is a public calendar
 * notice. None of them is a financial, access-control, or evidence-bearing
 * act, so none joins `SensitiveActions`.
 */
final class VisitationAuditActions
{
    public const string VISITATION_REQUESTED = 'VISITATION_REQUESTED';

    /**
     * One policy write (create OR edit — the resource's create page and
     * edit page both route through `CemeteryVisitationPolicyResource`
     * writes of the single per-cemetery row), committed with the row in
     * one transaction by `Audit::wrap`.
     */
    public const string CEMETERY_VISITATION_POLICY_UPDATED = 'CEMETERY_VISITATION_POLICY_UPDATED';

    public const string VISITATION_BLACKOUT_CREATED = 'VISITATION_BLACKOUT_CREATED';

    public const string VISITATION_BLACKOUT_DELETED = 'VISITATION_BLACKOUT_DELETED';

    /**
     * An operator status transition (`ChangeVisitationBookingStatus`) —
     * requested→confirmed/cancelled/no_show and confirmed→cancelled.
     */
    public const string VISITATION_STATUS_CHANGED = 'VISITATION_STATUS_CHANGED';
}
