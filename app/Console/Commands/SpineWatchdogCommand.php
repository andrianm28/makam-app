<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\RefundObligation\RefundObligationDeadlineQuery;
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
 * Six independent signals, any one of which is a real problem
 * ---------------------------------------------------------------------------
 * Signals 1-4 detect MACHINERY that has stopped. Signals 5-6, added 14 Sep
 * 2026 by the refund plan's R4, detect something different in kind: a PERSON
 * who has not acted, on money the platform already took from a bereaved
 * family and owes back. No retry drains those, and no amount of healthy
 * infrastructure makes them go away — which is precisely why they belong in
 * the one command an operator is already watching, rather than in a table
 * someone has to remember to open.
 *
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
 *   4. A `notification_deliveries` row that reached `FAILED` (its bounded
 *      retry exhausted — `Jobs\RetryFailedDeliveryJob::MAX_ATTEMPTS`) within
 *      the last `--failed-deliveries-window-minutes` — NOTIF-06, 07 Sep
 *      2026. Before this signal, a permanently-failed delivery was
 *      completely invisible: no operator surface, no alert, nothing in this
 *      command's own coverage. Same recent-window shape as signal 3, for
 *      the same statelessness reason.
 *
 *   5. A `refund_obligations` row still `TERUTANG` past its `due_at` — money
 *      taken from a family and not returned by the deadline the owner set
 *      (3 working days). Refund plan R4.
 *   6. A `refund_obligations` row still `TERUTANG` and falling due within
 *      `--refund-due-soon-hours`. Signal 5 alone would be too late to help:
 *      the provider supports withdraw-to-main-account only, so execution is
 *      two manual bank movements and the first settles on the provider's
 *      clock. An alarm at the deadline fires after it could have been acted
 *      on; this one fires while there is still time to start.
 *
 * Each is independently actionable and independently caused, so all six
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
        {--stuck-outbox-minutes=10 : Alert when an outbox event has been claimed and pushed to the queue this long without publishing}
        {--stale-delivery-minutes=15 : Alert when a notification delivery has waited this long queued}
        {--failed-jobs-window-minutes=5 : Alert on any failed job within this recent window}
        {--failed-deliveries-window-minutes=15 : Alert on any permanently-failed notification delivery within this recent window}
        {--refund-due-soon-hours=24 : Warn when an unpaid refund obligation is this close to its deadline}';

    protected $description = 'Detect a silently stalled outbox publisher or notification queue worker, '
        .'and refund debt running out of time.';

    public function handle(): int
    {
        $problems = array_filter([
            $this->checkStaleOutbox((int) $this->option('stale-outbox-minutes')),
            $this->checkStuckInFlightOutbox((int) $this->option('stuck-outbox-minutes')),
            $this->checkStaleDeliveries((int) $this->option('stale-delivery-minutes')),
            $this->checkRecentFailures((int) $this->option('failed-jobs-window-minutes')),
            $this->checkFailedDeliveries((int) $this->option('failed-deliveries-window-minutes')),
            $this->checkOverdueRefundObligations(),
            $this->checkRefundObligationsDueSoon((int) $this->option('refund-due-soon-hours')),
        ]);

        if ($problems === []) {
            $this->info('Spine healthy: outbox draining, deliveries flowing, no recent failed jobs, no refund debt running late.');

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

    /**
     * QUE-04's new signal: "dispatched but never consumed" — a row
     * `OutboxPublisher::dispatchOne()` claimed (`locked_at` set) and handed
     * to the queue driver, but `PublishOutboxEventJob::handle()` never
     * completed for it (`dispatched_at` still null). `checkStaleOutbox()`
     * above eventually catches this too (it ages off `occurred_at`,
     * independent of claim state), but that signal cannot tell "never
     * claimed at all" apart from "claimed, queued, and stuck" — the two
     * have very different causes (scheduler/publisher not running, versus a
     * crash-looping worker or a permanently-failed job that never reached
     * its own `failed()` hook). This check is keyed on `locked_at` instead
     * of `occurred_at` specifically to surface the second case fast, without
     * waiting for `OutboxPublisher::STALE_CLAIM_SECONDS` to lapse before a
     * human even finds out.
     */
    private function checkStuckInFlightOutbox(int $minutes): ?string
    {
        $count = DB::table('outbox_events')
            ->whereNull('dispatched_at')
            ->whereNotNull('locked_at')
            ->where('locked_at', '<', now()->subMinutes($minutes))
            ->count();

        if ($count === 0) {
            return null;
        }

        return "Outbox events stuck in flight: {$count} event(s) claimed and dispatched to the queue over ".
            "{$minutes} minute(s) ago but never published. Check for a crash-looping worker or a ".
            'permanently-failed PublishOutboxEventJob (failed_jobs), then consider outbox:replay.';
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

    /**
     * NOTIF-06: a delivery that reached `FAILED` has already exhausted its
     * bounded retry (`Jobs\RetryFailedDeliveryJob::MAX_ATTEMPTS`) — nothing
     * will ever move it forward on its own. Counts and durations only, per
     * this class's own "Restricted data" section — no event name, recipient
     * reference, or delivery id.
     */
    private function checkFailedDeliveries(int $minutes): ?string
    {
        $count = DB::table('notification_deliveries')
            ->where('state', DeliveryState::Failed->value)
            ->where('updated_at', '>=', now()->subMinutes($minutes))
            ->count();

        if ($count === 0) {
            return null;
        }

        return "{$count} notification delivery(ies) permanently failed in the last {$minutes} minute(s). ".
            'Check the admin "Notifikasi gagal" page.';
    }

    /**
     * Refund plan R4, first half: a debt past its deadline and still unpaid.
     *
     * This one differs in kind from the four signals above it. Those detect
     * MACHINERY that has stopped — a publisher, a worker, a job. This detects
     * a PERSON who has not acted, on money the platform already took from a
     * family and owes back. Nothing will retry it, nothing will drain it, and
     * no amount of healthy infrastructure makes it go away.
     *
     * The plan's own sentence for this state, kept beside the check that
     * finds it: *"Kewajiban yang diam adalah kewajiban yang dilupakan, dan
     * yang menanggung lupanya adalah keluarga yang sudah membayar."*
     *
     * Counts and a deadline only — no order reference, no amount, no actor.
     * Per this class's own "Restricted data" section, and because a refund
     * obligation is attached to a bereaved family by definition.
     */
    private function checkOverdueRefundObligations(): ?string
    {
        $deadlines = app(RefundObligationDeadlineQuery::class);

        $count = $deadlines->overdueCount();

        if ($count === 0) {
            return null;
        }

        $oldest = $deadlines->oldestOverdueDueAt();
        $since = $oldest === null ? '' : " Oldest deadline passed at {$oldest}.";

        return "{$count} refund obligation(s) are PAST their execution deadline and still unpaid.".$since.
            ' Money was taken and has not been returned. Open the admin "Kewajiban Refund" list, '.
            'which is ordered by deadline.';
    }

    /**
     * Refund plan R4, second half — and the half the plan did not originally
     * ask for.
     *
     * R4 as written says overdue obligations must be loud. The owner then
     * supplied a fact (14 Sep 2026) that makes "overdue" too late to be the
     * only signal: SumoPod supports **withdraw to the main account only**, so
     * a refund is two manual bank movements, and the first settles on the
     * provider's clock rather than ours.
     *
     * An operator who first hears about a debt ON its deadline cannot start a
     * withdraw and finish a transfer in zero time. So the alarm that actually
     * prevents a missed deadline is this one, not the one above — the one
     * above reports a failure that already happened.
     *
     * Deliberately a separate message rather than a severity flag on the
     * first: the two demand different actions. "Begin the withdraw" and "this
     * family has waited too long and someone must tell them why" are not the
     * same instruction, and collapsing them would lose the one that is still
     * actionable.
     */
    private function checkRefundObligationsDueSoon(int $hours): ?string
    {
        $count = app(RefundObligationDeadlineQuery::class)->dueSoonCount($hours);

        if ($count === 0) {
            return null;
        }

        return "{$count} refund obligation(s) fall due within {$hours} hour(s) and are still unpaid. ".
            'The provider supports withdraw-to-main-account only, so execution is two manual bank '.
            'movements and the first settles on the provider\'s clock — start the withdraw now, not '.
            'on the deadline.';
    }
}
