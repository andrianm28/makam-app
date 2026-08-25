<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Platform\Notification\DeliveryState;
use App\Platform\Observability\SpineDegradedException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * `php artisan spine:watchdog`
 *
 * The highest-value alert this codebase can cheaply have: detects the async
 * spine (`outbox:publish` -> the queue worker -> `Channels\MailChannel`)
 * dying silently while the public site keeps returning 200s. A customer
 * completes a booking, the confirmation page renders correctly, and nothing
 * ever reaches them — the exact failure mode `docs/superpowers/plans/
 * 2026-08-18-public-beta-release.md` names as the single most likely beta
 * incident, precisely because every layer upstream of it (the HTTP request,
 * the order, the quote) succeeds and looks healthy.
 *
 * ---------------------------------------------------------------------------
 * Three independent signals, any one of which is a real problem
 * ---------------------------------------------------------------------------
 *   1. An `outbox_events` row unwatched (`dispatched_at IS NULL`) for longer
 *      than `--stale-outbox-minutes` — the publisher
 *      (`Console\Commands\OutboxPublishCommand`) has stopped running, or
 *      the scheduler itself has stopped.
 *   2. A `notification_deliveries` row stuck `QUEUED` for longer than
 *      `--stale-delivery-minutes` — the outbox is draining but the QUEUE
 *      WORKER consuming `SendNotificationChannelJob` has stopped (a
 *      distinct process from the scheduler; either can die independently).
 *   3. Any `failed_jobs` row within the last `--failed-jobs-window-minutes`
 *      — a job is failing outright, not just queuing up. A COUNT within a
 *      recent window, not a stored "since last run" delta: this command is
 *      a stateless scheduled invocation with no memory of its previous run,
 *      and a time window sidesteps needing any (a cache key or a table
 *      would be one more thing that can itself silently stop working).
 *
 * Each is independently actionable and independently caused, so all three
 * are always checked and reported together — one exception per problem
 * found, not one exception for "something is wrong."
 *
 * ---------------------------------------------------------------------------
 * Why `report()`, not a direct Sentry/webhook call
 * ---------------------------------------------------------------------------
 * This command has no opinion on WHERE an alert ends up — that is exactly
 * what Laravel's exception-reporting pipeline (`bootstrap/app.php`'s
 * `withExceptions()`) already exists to route, and it is the seam an error
 * tracker hooks into with zero change to this class once one is installed.
 * Coupling this command to a specific provider's SDK would mean rewriting
 * detection logic to change alert routing — two concerns that should never
 * require touching the same class.
 *
 * ---------------------------------------------------------------------------
 * Restricted data
 * ---------------------------------------------------------------------------
 * Every message below carries counts and durations ONLY — no event name, no
 * payload, no recipient reference, no order id. `AGENTS.md` §Observability:
 * "Never place restricted data in logs, Pulse, Horizon tags, or error
 * trackers."
 */
final class SpineWatchdogCommand extends Command
{
    protected $signature = 'spine:watchdog
        {--stale-outbox-minutes=5 : Alert when an outbox event has waited this long undispatched}
        {--stale-delivery-minutes=15 : Alert when a notification delivery has waited this long queued}
        {--failed-jobs-window-minutes=5 : Alert on any failed job within this recent window}';

    protected $description = 'Detect a silently stalled outbox publisher or notification queue worker.';

    public function handle(): int
    {
        $problems = array_filter([
            $this->checkStaleOutbox((int) $this->option('stale-outbox-minutes')),
            $this->checkStaleDeliveries((int) $this->option('stale-delivery-minutes')),
            $this->checkRecentFailures((int) $this->option('failed-jobs-window-minutes')),
        ]);

        if ($problems === []) {
            $this->info('Spine healthy: outbox draining, deliveries flowing, no recent failed jobs.');

            return self::SUCCESS;
        }

        foreach ($problems as $message) {
            $this->error($message);
            report(new SpineDegradedException($message));
        }

        return self::FAILURE;
    }

    private function checkStaleOutbox(int $minutes): ?string
    {
        $count = DB::table('outbox_events')
            ->whereNull('dispatched_at')
            ->where('occurred_at', '<', now()->subMinutes($minutes))
            ->count();

        if ($count === 0) {
            return null;
        }

        return "Outbox publisher stalled: {$count} event(s) undispatched for over {$minutes} minute(s). ".
            'Check that outbox:publish is still scheduled and running.';
    }

    private function checkStaleDeliveries(int $minutes): ?string
    {
        $count = DB::table('notification_deliveries')
            ->where('state', DeliveryState::Queued->value)
            ->where('created_at', '<', now()->subMinutes($minutes))
            ->count();

        if ($count === 0) {
            return null;
        }

        return "Notification queue worker stalled: {$count} delivery(ies) queued for over {$minutes} minute(s). ".
            'Check that the queue:work process is still running.';
    }

    private function checkRecentFailures(int $minutes): ?string
    {
        $count = DB::table('failed_jobs')
            ->where('failed_at', '>=', now()->subMinutes($minutes))
            ->count();

        if ($count === 0) {
            return null;
        }

        return "{$count} job(s) failed outright in the last {$minutes} minute(s). Check failed_jobs.";
    }
}
