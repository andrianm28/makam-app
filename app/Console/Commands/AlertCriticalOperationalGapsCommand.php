<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Platform\DocumentVault\DocumentState;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * `php artisan alert:critical-operational-gaps`
 *
 * Stop-gap visibility for two Critical/Medium findings from the 6 Sep 2026
 * full audit while their permanent fixes land separately in Phase 1 of
 * `docs/superpowers/plans/2026-09-06-remediasi-audit-makam.md`:
 *
 *   1. **COORD-07 (Critical)**: no worker on the deployed beta host consumes
 *      the `media` queue, so `ScanDocumentJob` dispatches there and every
 *      document sits in quarantine forever — silently, with no observable
 *      symptom anywhere else in the system. This command surfaces the
 *      backlog directly by counting documents whose state is not yet
 *      terminal (`ACCEPTED`/`REJECTED`/`EXPIRED`/`DELETED`) and are older
 *      than `--stale-document-minutes`.
 *   2. **COORD-15 (Medium)**: `failed_jobs` rows on the `critical`/`urgent`
 *      queues can sit unretried indefinitely with nothing alerting on a
 *      non-empty table — confirmed live on beta (6 rows from a 25 Aug
 *      payment-provider outage, untouched 12 days later). This counts the
 *      current BACKLOG DEPTH on those two queues regardless of age, which
 *      is deliberately different from `spine:watchdog`'s existing
 *      recent-failure-window check (that command answers "is something
 *      failing right now"; this one answers "is there an unresolved pile
 *      sitting here").
 *
 * This command mutates nothing and never fails a deploy — it is pure
 * observability, reported via `Log::warning()` so it reaches whatever
 * channel/error-tracker is already configured (Sentry, if wired, per the
 * same seam `SpineWatchdogCommand` documents). Every message below carries
 * counts and durations only — no document id, no owner reference, no job
 * payload. `AGENTS.md` §Observability: "Never place restricted data in
 * logs, Pulse, Horizon tags, or error trackers."
 */
final class AlertCriticalOperationalGapsCommand extends Command
{
    protected $signature = 'alert:critical-operational-gaps
        {--stale-document-minutes=60 : Alert when a document has sat in a non-terminal state this long}';

    protected $description = 'Report stale quarantined documents and unresolved critical/urgent failed jobs (stop-gap visibility for COORD-07/COORD-15).';

    private const TERMINAL_DOCUMENT_STATES = [
        DocumentState::Accepted->value,
        DocumentState::Rejected->value,
        DocumentState::Expired->value,
        DocumentState::Deleted->value,
    ];

    private const WATCHED_QUEUES = ['critical', 'urgent'];

    public function handle(): int
    {
        $staleDocuments = $this->countStaleDocuments((int) $this->option('stale-document-minutes'));
        $failedJobs = $this->countFailedJobsOnWatchedQueues();

        if ($staleDocuments === 0 && $failedJobs === 0) {
            $this->info('No stale quarantined documents; no unresolved failed jobs on critical/urgent queues.');

            return self::SUCCESS;
        }

        if ($staleDocuments > 0) {
            $minutes = (int) $this->option('stale-document-minutes');
            $message = "{$staleDocuments} document(s) have sat in a non-terminal quarantine state for over {$minutes} minute(s). ".
                'Confirm a worker is consuming the media queue (COORD-07).';
            $this->warn($message);
            Log::warning($message);
        }

        if ($failedJobs > 0) {
            $message = "{$failedJobs} job(s) sit unresolved in failed_jobs on the critical/urgent queues. ".
                'Decide retry or discard for each (COORD-15).';
            $this->warn($message);
            Log::warning($message);
        }

        return self::SUCCESS;
    }

    private function countStaleDocuments(int $minutes): int
    {
        return (int) DB::table('documents')
            ->whereNotIn('state', self::TERMINAL_DOCUMENT_STATES)
            ->where('created_at', '<', now()->subMinutes($minutes))
            ->count();
    }

    private function countFailedJobsOnWatchedQueues(): int
    {
        return (int) DB::table('failed_jobs')
            ->whereIn('queue', self::WATCHED_QUEUES)
            ->count();
    }
}
