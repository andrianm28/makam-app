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
