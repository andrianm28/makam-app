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
use Throwable;

/**
 * Recovery entry point for scheduler/worker supervision. It discovers marker
 * rows whose original cleanup job was lost and removes accepted-prefix
 * orphans belonging to documents that never reached ACCEPTED.
 */
final class ReconcileDocumentStorageCleanupJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 5;

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

        Document::query()
            ->where('storage_prefix', '!=', 'accepted')
            ->whereIn('state', [
                DocumentState::Quarantined->value,
                DocumentState::Scanning->value,
                DocumentState::Rejected->value,
                DocumentState::Expired->value,
                DocumentState::Deleted->value,
            ])
            ->cursor()
            ->each(function (Document $document) use ($objectStorage, $pathResolver, &$failures): void {
                try {
                    (new CleanupPromotedDocumentStorageJob(
                        documentId: $document->getKey(),
                        reconcileAcceptedCopy: true,
                    ))->handle($objectStorage, $pathResolver);
                } catch (Throwable $exception) {
                    $failures[] = $exception;
                }
            });

        if ($failures !== []) {
            throw $failures[0];
        }
    }
}
