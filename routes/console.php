<?php

use App\Platform\Analytics\Models\MenuInteractionEvent;
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
//
// QUE-07: an explicit 5-minute expiry, not the framework's 24-hour mutex
// default. Without one, an ungracefully-killed run (an OOM-killed process,
// a `kill -9`, a host reboot mid-tick) leaves the overlap mutex held for a
// full day — silencing the ENTIRE async event pipeline for up to 24 hours
// even though `outbox:publish` itself runs every minute and the belt-and-
// braces reasoning above already makes true concurrent overlap safe. 5
// minutes comfortably exceeds any normal run (bounded by
// `OutboxPublishCommand::DEFAULT_MAX_BATCHES`) while capping a stuck mutex's
// blast radius to a few missed ticks instead of a day. Manual recovery if a
// mutex is ever suspected stuck before its expiry:
// `php artisan schedule:clear-cache` — documented in
// `docs/operations/dev-staging-environment.md`.
Schedule::command('outbox:publish')->everyMinute()->withoutOverlapping(5);

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
//
// QUE-07: same explicit-expiry reasoning as outbox:publish immediately
// above — an every-minute job with the 24-hour default mutex would leave
// plots falsely stuck "reserved" for up to a day after one ungraceful kill.
Schedule::command('plot-reservation:expire-stale-draft-holds')->everyMinute()->withoutOverlapping(5);

// Detects a silently stalled outbox publisher or notification queue
// worker — see SpineWatchdogCommand's own doc block for why this is the
// highest-value alert available: every layer upstream of the async spine
// can look perfectly healthy while it has quietly stopped. Every five
// minutes, independent of outbox:publish's own every-minute schedule —
// the watchdog must keep running even if the thing it watches has died.
//
// QUE-07: a 10-minute explicit expiry — longer than outbox:publish's/
// plot-reservation's 5 minutes because this job's own cadence is 5 minutes
// longer, but still far short of the 24-hour default: the watchdog is the
// alert of last resort, so its own mutex getting stuck silent for a day
// would be the worst possible instance of the exact failure mode it exists
// to detect.
Schedule::command('spine:watchdog')->everyFiveMinutes()->withoutOverlapping(10);

// Stop-gap visibility for audit findings COORD-07 (Critical: no worker
// consumes the media queue, so quarantined documents can never be scanned)
// and COORD-15 (failed critical/urgent jobs sitting unretried with nothing
// surfacing them) while their permanent fixes land in Phase 1 of
// docs/superpowers/plans/2026-09-06-remediasi-audit-makam.md. Mutates
// nothing — see AlertCriticalOperationalGapsCommand's own doc block.
Schedule::command('alert:critical-operational-gaps')->everyFiveMinutes()->withoutOverlapping();

// QUE-09: `ReconcileDocumentStorageCleanupJob` is a documented recovery
// entry point (its own class doc block: "Recovery entry point for
// scheduler/worker supervision") that, until this line, had NO dispatcher
// anywhere — no scheduler entry, no other periodic task calling it. A
// crashed `CleanupPromotedDocumentStorageJob` (the job normally responsible
// for removing a promoted document's original storage copy) or a document
// that never reached ACCEPTED left its orphaned storage object
// permanently un-swept; nothing in the application would ever notice or
// retry.
//
// Hourly (matching orders:expire-stale-quotes's cadence — storage cleanup
// is not customer-latency-sensitive the way outbox draining is) via the
// thin `documents:reconcile-storage-cleanup` wrapper command.
// `withoutOverlapping(30)` follows the same QUE-07 explicit-expiry
// reasoning as the entries above, sized to this job's own bounded runtime
// rather than reused from a faster job's number.
//
// Dependency this line does NOT fix, and is not this batch's to fix
// (QUE-01, a separate host-infra item, out of scope here): the job
// dispatches its own recovery work onto the `media` queue
// (`CleanupPromotedDocumentStorageJob::$queue`/routing), and per QUE-01 the
// `media` queue currently has no consumer running in beta. Wiring this
// schedule entry is correct regardless — the reconciliation logic and its
// scheduled trigger are right even while the queue it drains into is
// unstaffed; it starts working the moment a `media` worker exists, with no
// further change needed here.
Schedule::command('documents:reconcile-storage-cleanup')->hourly()->withoutOverlapping(30);

// PERF-06 — `menu_interaction_events` is written on every homepage view
// (App\Livewire\Public\HomePage::mount(), via App\Jobs\
// RecordMenuImpressions) and was never pruned before this. Daily, matching
// the cadence of the other low-urgency retention job above
// (booking:purge-stale-drafts) — a write-only analytics table has no
// customer-facing staleness window to protect.
Schedule::command('model:prune', ['--model' => [MenuInteractionEvent::class]])->daily();
