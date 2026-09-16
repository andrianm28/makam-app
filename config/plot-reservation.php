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
    | RAISED 15 -> 60, 14 Sep 2026 (pay-first plan, Tahap 7)
    | --------------------------------------------------------------------
    | The original 15 was chosen when payment happened AFTER an operator
    | confirmed availability, so the hold only had to survive a form. It no
    | longer does: the customer now leaves the site for a hosted checkout
    | and comes back.
    |
    | The number that exposed this is the provider's own. Every real
    | `payment_sessions` row on dev carries `expires_at = created_at + 24h`
    | (measured 14 Sep 2026 on both rows that exist). So the customer is
    | handed a payment link valid for a DAY while the plot behind it was
    | held for a QUARTER HOUR — the link outlived the hold by 96x. A
    | customer who opened the link, switched to their bank app, and paid
    | twenty minutes later had already lost the plot, and the payment still
    | succeeded.
    |
    | 60 is a deliberate middle, not a match to the provider:
    |
    |   - It covers a real attempt end to end — open the link, switch to an
    |     e-wallet or banking app, fail a PIN, retry — which 15 does not.
    |   - It does NOT match the provider's 24h, because a hold is a promise
    |     made to everyone ELSE looking at that plot. One abandoned tab must
    |     not lock a grave for a day.
    |
    | That leaves a deliberate, NAMED residual gap: between 60 minutes and
    | 24 hours a payment can still settle against a plot whose hold has
    | lapsed. Raising this number narrows the window; it cannot close it,
    | because the two clocks belong to two different systems. Closing it is
    | the anti-oversell condition of the pay-first plan's Tahap 3 — the
    | condition that plan calls "yang paling penting di seluruh rencana
    | ini" — which refuses a settlement whose plot is no longer held by the
    | payer. Until Tahap 3 lands, this config is a mitigation and is
    | documented as one.
    |
    */
    'draft_hold_ttl_minutes' => (int) env('PLOT_DRAFT_HOLD_TTL_MINUTES', 60),
];
