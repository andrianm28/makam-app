<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Platform\DocumentVault\Jobs\ReconcileDocumentStorageCleanupJob;
use App\Platform\Outbox\OutboxQueueName;
use Illuminate\Console\Command;

/**
 * `php artisan documents:reconcile-storage-cleanup`
 *
 * QUE-09: `ReconcileDocumentStorageCleanupJob`'s own class doc block calls
 * it "the recovery entry point for scheduler/worker supervision" — but
 * until this command, nothing in the application ever dispatched it. No
 * scheduler entry, no other periodic task called it, no operator command
 * existed to run it on demand. A crashed `CleanupPromotedDocumentStorageJob`
 * or a document that never reached ACCEPTED left its orphaned storage
 * object permanently un-swept, with no automatic path back to consistency.
 *
 * A thin wrapper rather than scheduling the job class directly:
 * `Schedule::job()` would work too, but a named `artisan` command gives an
 * operator a way to run reconciliation on demand (after an incident, before
 * a storage audit) with the exact same code path the schedule uses — one
 * entry point, two callers (cron and a human), rather than the job class
 * being the only handle on this behaviour.
 *
 * Dispatched onto the `media` queue (`OutboxQueueName::Media`), matching
 * every other `App\Platform\DocumentVault\Jobs\*` job's queue placement
 * (e.g. `ScanDocumentJob`, per `docs/superpowers/plans/
 * 2026-08-09-platform-document-vault.md`'s "import scans are `media` not
 * `imports` so they don't starve the batch queue"). QUE-01 (a separate,
 * out-of-scope host-infra item) notes the `media` queue currently has no
 * consumer running in beta — this command's scheduling is still correct
 * regardless: it starts draining the moment a `media` worker exists, with
 * no further change needed here.
 */
final class DocumentsReconcileStorageCleanupCommand extends Command
{
    protected $signature = 'documents:reconcile-storage-cleanup';

    protected $description = 'Dispatch the document-vault storage cleanup reconciliation sweep onto the media queue.';

    public function handle(): int
    {
        ReconcileDocumentStorageCleanupJob::dispatch()->onQueue(OutboxQueueName::Media->value);

        $this->info('Dispatched ReconcileDocumentStorageCleanupJob onto the media queue.');

        return self::SUCCESS;
    }
}
