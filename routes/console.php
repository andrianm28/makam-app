<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// DISABLED 6 Sep 2026 (audit finding DOM-01, Critical) — this sweep deletes
// any booking_draft whose updated_at is older than the retention window with
// NO check for dependent records. Because SubmitBookingDraft never touches a
// draft again after submission, updated_at freezes at submission time, so
// this job was on track to permanently null booking_draft_id on live orders,
// funeral cases, pre-need interests and plot reservations starting around
// 19 Sep 2026 (the oldest currently-linked draft's 30-day anniversary).
// Re-enabled with a dependency-aware predicate in the Phase 1 follow-up —
// see docs/superpowers/plans/2026-09-06-remediasi-audit-makam.md Task 1.1.
// Schedule::command('booking:purge-stale-drafts')->dailyAt('03:15');

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

// Stop-gap visibility for audit findings COORD-07 (Critical: no worker
// consumes the media queue, so quarantined documents can never be scanned)
// and COORD-15 (failed critical/urgent jobs sitting unretried with nothing
// surfacing them) while their permanent fixes land in Phase 1 of
// docs/superpowers/plans/2026-09-06-remediasi-audit-makam.md. Mutates
// nothing — see AlertCriticalOperationalGapsCommand's own doc block.
Schedule::command('alert:critical-operational-gaps')->everyFiveMinutes()->withoutOverlapping();
