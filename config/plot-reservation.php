<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Draft plot hold TTL
    |--------------------------------------------------------------------------
    |
    | How long a customer's step-2 plot pick (App\Domain\PlotReservation\
    | Actions\HoldPlotForDraft) reserves a specific grave plot before it is
    | swept back to available by the plot-reservation:expire-stale-draft-
    | holds scheduled command. A config value, not a literal, so it can
    | change without a deploy-and-decide cycle — see
    | docs/superpowers/plans/2026-08-29-customer-plot-picker-hold.md.
    |
    */
    'draft_hold_ttl_minutes' => (int) env('PLOT_DRAFT_HOLD_TTL_MINUTES', 15),
];
