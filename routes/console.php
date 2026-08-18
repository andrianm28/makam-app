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

// Detects a silently stalled outbox publisher or notification queue
// worker — see SpineWatchdogCommand's own doc block for why this is the
// highest-value alert available: every layer upstream of the async spine
// can look perfectly healthy while it has quietly stopped. Every five
// minutes, independent of outbox:publish's own every-minute schedule —
// the watchdog must keep running even if the thing it watches has died.
Schedule::command('spine:watchdog')->everyFiveMinutes()->withoutOverlapping();
