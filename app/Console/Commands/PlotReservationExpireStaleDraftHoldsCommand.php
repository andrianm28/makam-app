<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\PlotReservation\PlotReservationExpiryScheduler;
use Illuminate\Console\Command;

/**
 * `php artisan plot-reservation:expire-stale-draft-holds`
 *
 * Sweeps customer-abandoned Step 2 plot holds back to available.
 * `HoldPlotForDraft` is the only thing that creates a draft-anchored hold,
 * and until this command existed, nothing swept a hold the customer simply
 * closed the tab on — the plot would stay `reserved` forever, per the
 * roadmap's own "new scheduler required" note (`ExpirePlotReservation` is
 * operator-on-demand only).
 *
 * Scheduled every minute in `routes/console.php`, same cadence as
 * `outbox:publish` — a customer-visible plot staying falsely "reserved"
 * for a full nightly-batch window is not acceptable UX, unlike
 * `orders:expire-stale-quotes`'s cosmetic hourly cadence.
 *
 * Idempotent: `PlotReservationExpiryScheduler` silently skips a row that
 * has already moved on since it was queried — safe to re-run.
 *
 * Deliberately does NOT log or print plot/reservation content beyond a
 * count — same `AGENTS.md` §Observability discipline
 * `orders:expire-stale-quotes` and `booking:purge-stale-drafts` already
 * follow.
 */
final class PlotReservationExpireStaleDraftHoldsCommand extends Command
{
    protected $signature = 'plot-reservation:expire-stale-draft-holds';

    protected $description = 'Expire customer-abandoned draft plot holds and return their plots to available.';

    public function handle(PlotReservationExpiryScheduler $scheduler): int
    {
        $expired = $scheduler->expireStaleDraftHolds();

        $this->info("Expired {$expired->count()} stale draft plot hold(s).");

        return self::SUCCESS;
    }
}
