<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Retention for abandoned booking drafts, which hold customer and deceased
// personal data from Step 6 onward. Daily and off-peak; the window itself
// lives in config/booking.php.
Schedule::command('booking:purge-stale-drafts')->dailyAt('03:15');

// Generate due subscription cycles for active care subscriptions. Daily
// and off-peak, shortly after the draft-purge job.
Schedule::command('care:generate-cycles')->dailyAt('03:30');

// Drain the transactional outbox. This is the scheduler entry
// `docs/architecture/queue-and-outbox.md` §Publisher requires ("Scheduler
// runs a single outbox publisher using overlap prevention or distributed
// lock") and, until it existed, nothing in the application ever called
// `OutboxPublisher::publishBatch()` — every domain event written to
// `outbox_events` sat undispatched forever, taking the whole notification
// chain with it.
//
// Every minute, because notification latency is customer-visible: an order
// confirmation that waits for a nightly run is not a confirmation.
// `withoutOverlapping()` is belt-and-braces — `claim()` uses FOR UPDATE SKIP
// LOCKED, so concurrent publishers are already safe by construction.
Schedule::command('outbox:publish')->everyMinute()->withoutOverlapping();

// Read-model honesty for stale order quotes — the deferred half of Task 4's
// ratified design (Q4/Q5) in
// `docs/superpowers/plans/2026-08-12-platform-order-orchestration.md:592`:
// "expiry is evaluated lazily and authoritatively at guard time... with a
// scheduled job writing KEDALUWARSA only for read-model honesty." The guard
// (`Quote::isAcceptedAndUnexpired()`) and `Quote::accept()` already refuse an
// expired quote live, on every call, independent of this job — so no
// financial decision depends on its frequency or on it running at all.
// Hourly, because "expired 40 minutes ago and the screen still says quote
// sent" is a cosmetic staleness window, not a correctness one.
Schedule::command('orders:expire-stale-quotes')->hourly()->withoutOverlapping();

// Sweep customer-abandoned Step 2 plot holds (App\Domain\PlotReservation\
// Actions\HoldPlotForDraft) back to available. Every minute, matching
// outbox:publish's cadence — a plot showing falsely "reserved" to every
// other customer is directly revenue-visible, not a cosmetic staleness
// window like orders:expire-stale-quotes.
Schedule::command('plot-reservation:expire-stale-draft-holds')->everyMinute()->withoutOverlapping();

// Detects a silently stalled outbox publisher or notification queue
// worker — see SpineWatchdogCommand's own doc block for why this is the
// highest-value alert available: every layer upstream of the async spine
// can look perfectly healthy while it has quietly stopped. Every five
// minutes, independent of outbox:publish's own every-minute schedule —
// the watchdog must keep running even if the thing it watches has died.
Schedule::command('spine:watchdog')->everyFiveMinutes()->withoutOverlapping();
