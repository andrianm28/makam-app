<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Platform\Outbox\OutboxPublisher;
use Illuminate\Console\Command;

/**
 * `php artisan outbox:publish {--batch=} {--max-batches=}`
 *
 * The production caller for `OutboxPublisher::publishBatch()`.
 *
 * ---------------------------------------------------------------------------
 * Why this class exists
 * ---------------------------------------------------------------------------
 * `docs/architecture/queue-and-outbox.md` §Publisher requires "Scheduler runs
 * a single outbox publisher using overlap prevention or distributed lock."
 * Until this command, `publishBatch()` had NO production caller anywhere in
 * the application — only `tests/Feature/Outbox/*` invoked it. Every domain
 * event written to `outbox_events` therefore sat undispatched forever, and
 * with it the entire notification chain that hangs off
 * `OutboxEventPublished` (see `Platform\Notification\Listeners\
 * DispatchNotificationConsumerOnOutboxEventPublished`). The transactional
 * outbox was writing rows nothing drained.
 *
 * ---------------------------------------------------------------------------
 * Why a bounded loop rather than a single batch
 * ---------------------------------------------------------------------------
 * `publishBatch()` claims at most `$batchSize` rows per call. A single call
 * per scheduler tick would cap throughput at `batch/minute`, so a backlog
 * larger than that could never be worked off — it would fall further behind
 * every minute. This drains repeatedly until either a pass claims nothing
 * (the queue is empty) or `--max-batches` passes have run.
 *
 * The `--max-batches` cap is what keeps one invocation from running
 * unboundedly against a large backlog and colliding with the next scheduled
 * tick. It is a safety bound, not a throughput target: reaching it is
 * reported as a warning, because a run that always terminates on the cap
 * means the backlog is growing faster than it is drained and the schedule or
 * batch size needs revisiting.
 *
 * ---------------------------------------------------------------------------
 * Concurrency
 * ---------------------------------------------------------------------------
 * `withoutOverlapping()` in `routes/console.php` prevents two scheduled runs
 * from overlapping on one host. It is belt-and-braces rather than the primary
 * protection: `OutboxPublisher::claim()` uses `SELECT ... FOR UPDATE SKIP
 * LOCKED`, so concurrent publishers claim disjoint row sets and are safe by
 * construction. That is also why `claim()` hard-refuses any driver other than
 * `pgsql` — see its own doc block.
 *
 * Deliberately reports counts only, never event names, payloads or
 * identifiers: outbox payloads carry domain data and `AGENTS.md`
 * §Observability forbids restricted data in logs and command output.
 */
final class OutboxPublishCommand extends Command
{
    /**
     * The default number of drain passes per invocation. With the default
     * batch size of 50 this drains up to 1,000 events per scheduled tick,
     * which is far above any observed rate while still being bounded.
     */
    public const int DEFAULT_MAX_BATCHES = 20;

    protected $signature = 'outbox:publish
        {--batch= : Rows to claim per pass; defaults to OutboxPublisher::DEFAULT_BATCH_SIZE}
        {--max-batches= : Maximum drain passes for this invocation; defaults to '.self::DEFAULT_MAX_BATCHES.'}';

    protected $description = 'Claim undispatched outbox events and dispatch them to their queues.';

    public function handle(OutboxPublisher $publisher): int
    {
        $batchSize = $this->option('batch') !== null
            ? (int) $this->option('batch')
            : OutboxPublisher::DEFAULT_BATCH_SIZE;

        $maxBatches = $this->option('max-batches') !== null
            ? (int) $this->option('max-batches')
            : self::DEFAULT_MAX_BATCHES;

        if ($batchSize < 1) {
            $this->error('The batch size must be at least 1.');

            return self::FAILURE;
        }

        if ($maxBatches < 1) {
            $this->error('The maximum number of batches must be at least 1.');

            return self::FAILURE;
        }

        $totalClaimed = 0;
        $passes = 0;

        while ($passes < $maxBatches) {
            $claimed = $publisher->publishBatch($batchSize);
            $passes++;

            if ($claimed === 0) {
                break;
            }

            $totalClaimed += $claimed;
        }

        if ($totalClaimed === 0) {
            $this->info('No outbox events were waiting.');

            return self::SUCCESS;
        }

        $this->info("Claimed {$totalClaimed} outbox event(s) across {$passes} pass(es).");

        // Terminating on the cap means the backlog was still non-empty when
        // this invocation stopped. Surfaced as a warning so a persistently
        // capped run is visible rather than looking like a clean drain.
        if ($passes === $maxBatches) {
            $this->warn(
                "Stopped at the {$maxBatches}-pass cap with events still waiting. ".
                'If this recurs, raise --batch/--max-batches or shorten the schedule interval.'
            );
        }

        return self::SUCCESS;
    }
}
