<?php

declare(strict_types=1);

namespace App\Platform\DocumentVault\Jobs;

use App\Platform\DocumentVault\Contracts\ObjectStorage;
use App\Platform\DocumentVault\Contracts\StoragePathResolver;
use App\Platform\DocumentVault\DocumentState;
use App\Platform\DocumentVault\Models\Document;
use App\Platform\DocumentVault\Models\DocumentStorageCleanup;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Recovery entry point for scheduler/worker supervision. It discovers marker
 * rows whose original cleanup job was lost and removes accepted-prefix
 * orphans belonging to documents that never reached ACCEPTED.
 *
 * The orphan scan below is bounded per run (PERF-01): terminal states
 * (rejected/expired/deleted) never leave the `storage_prefix != accepted`
 * predicate, so an unbounded scan only grows over the app's lifetime. A
 * `limit()` plus a persisted `(updated_at, id)` watermark turns this into a
 * bounded, resumable cursor: each 5-minute run advances the cursor by at
 * most `orphanScanLimit()` rows, and once a full pass completes the
 * watermark resets to the beginning so the scan keeps self-healing forever
 * without ever examining more than one page of rows per invocation. The
 * handler this calls (`CleanupPromotedDocumentStorageJob::reconcileAcceptedCopy`)
 * is idempotent (`ObjectStorage::deleteAcceptedIfExists`), so re-visiting an
 * already-clean document on the next pass is a no-op, not a correctness risk.
 */
final class ReconcileDocumentStorageCleanupJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 5;

    private const string WATERMARK_CACHE_KEY = 'document-vault:reconcile-storage-cleanup:watermark';

    /**
     * Maximum orphan-document rows scanned per run — `config('document-
     * vault.reconcile_orphan_scan_limit')`, default 500. Keeps each
     * invocation of this every-5-minutes scheduled job bounded regardless
     * of how large the terminal-state backlog grows. Config-driven (rather
     * than a class constant) so tests can exercise the bounding/watermark
     * behaviour without seeding hundreds of real rows.
     */
    private function orphanScanLimit(): int
    {
        return (int) config('document-vault.reconcile_orphan_scan_limit', 500);
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 300, 900, 1800, 3600];
    }

    public function handle(ObjectStorage $objectStorage, StoragePathResolver $pathResolver): void
    {
        $failures = [];

        DocumentStorageCleanup::query()
            ->whereNull('completed_at')
            ->where('available_at', '<=', now())
            ->pluck('id')
            ->each(function (int|string $cleanupId) use ($objectStorage, $pathResolver, &$failures): void {
                try {
                    (new CleanupPromotedDocumentStorageJob(cleanupId: (int) $cleanupId)
                    )->handle($objectStorage, $pathResolver);
                } catch (Throwable $exception) {
                    $failures[] = $exception;
                }
            });

        $this->reconcileOrphanDocuments($objectStorage, $pathResolver, $failures);

        if ($failures !== []) {
            throw $failures[0];
        }
    }

    /**
     * @param  list<Throwable>  $failures
     */
    private function reconcileOrphanDocuments(ObjectStorage $objectStorage, StoragePathResolver $pathResolver, array &$failures): void
    {
        /** @var array{updated_at: string, id: string}|null $watermark */
        $watermark = Cache::get(self::WATERMARK_CACHE_KEY);

        $query = Document::query()
            ->where('storage_prefix', '!=', 'accepted')
            ->whereIn('state', [
                DocumentState::Quarantined->value,
                DocumentState::Scanning->value,
                DocumentState::Rejected->value,
                DocumentState::Expired->value,
                DocumentState::Deleted->value,
            ]);

        if ($watermark !== null) {
            $query->where(function ($outer) use ($watermark): void {
                $outer->where('updated_at', '>', $watermark['updated_at'])
                    ->orWhere(function ($inner) use ($watermark): void {
                        $inner->where('updated_at', '=', $watermark['updated_at'])
                            ->where('id', '>', $watermark['id']);
                    });
            });
        }

        $documents = $query
            ->orderBy('updated_at')
            ->orderBy('id')
            ->limit($this->orphanScanLimit())
            ->get();

        foreach ($documents as $document) {
            try {
                (new CleanupPromotedDocumentStorageJob(
                    documentId: $document->getKey(),
                    reconcileAcceptedCopy: true,
                ))->handle($objectStorage, $pathResolver);
            } catch (Throwable $exception) {
                $failures[] = $exception;
            }
        }

        if ($documents->count() < $this->orphanScanLimit()) {
            // Full pass completed this run; restart from the beginning next
            // time so newly-terminal documents are eventually revisited.
            Cache::forget(self::WATERMARK_CACHE_KEY);

            return;
        }

        $last = $documents->last();

        Cache::put(self::WATERMARK_CACHE_KEY, [
            'updated_at' => $last->updated_at?->toDateTimeString(),
            'id' => $last->getKey(),
        ], now()->addDay());
    }
}
