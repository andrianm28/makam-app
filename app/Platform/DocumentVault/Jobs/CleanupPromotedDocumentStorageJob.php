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
use Illuminate\Support\Facades\DB;

/**
 * Idempotent post-transaction storage cleanup. A committed promotion removes
 * quarantine only through its durable marker; a rolled-back promotion removes
 * only the uncommitted accepted copy. Neither path can delete the source
 * before the database commit.
 */
final class CleanupPromotedDocumentStorageJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 5;

    /**
     * @param  bool  $reconcileAcceptedCopy  True when the promotion transaction
     *                                       rolled back and the accepted object
     *                                       must be reconciled instead.
     */
    public function __construct(
        public readonly ?string $documentId = null,
        public readonly bool $reconcileAcceptedCopy = false,
        public readonly ?int $cleanupId = null,
    ) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 300, 900, 1800, 3600];
    }

    public function handle(ObjectStorage $objectStorage, StoragePathResolver $pathResolver): void
    {
        if ($this->reconcileAcceptedCopy) {
            $this->reconcileAcceptedCopy($objectStorage, $pathResolver);

            return;
        }

        $cleanup = DB::transaction(function (): ?DocumentStorageCleanup {
            $cleanup = DocumentStorageCleanup::query()
                ->whereNull('completed_at')
                ->where('available_at', '<=', now())
                ->when(
                    $this->cleanupId !== null,
                    fn ($query) => $query->whereKey($this->cleanupId),
                    fn ($query) => $query->where('document_id', $this->documentId),
                )
                ->lockForUpdate()
                ->first();

            if ($cleanup instanceof DocumentStorageCleanup) {
                $cleanup->markAttempt();
            }

            return $cleanup;
        });

        if (! $cleanup instanceof DocumentStorageCleanup) {
            return;
        }

        $document = Document::query()->find($cleanup->document_id);

        if (! $document instanceof Document) {
            return;
        }

        try {
            if (
                $cleanup->operation !== DocumentStorageCleanup::QUARANTINE_DELETE
                || $document->storage_prefix !== 'accepted'
                || ! in_array($document->state, [DocumentState::Accepted, DocumentState::Deleted, DocumentState::Expired], true)
            ) {
                throw new \LogicException('Promotion cleanup is waiting for an accepted document state.');
            }

            $quarantinePath = $pathResolver->quarantinePath($document->document_kind, $document->storage_key);
            $objectStorage->deleteIfExists($quarantinePath);
            $cleanup->markCompleted();
        } catch (\Throwable $exception) {
            $cleanup->markFailed();

            throw $exception;
        }
    }

    private function reconcileAcceptedCopy(ObjectStorage $objectStorage, StoragePathResolver $pathResolver): void
    {
        if ($this->documentId === null) {
            return;
        }

        $document = Document::query()->find($this->documentId);

        if (! $document instanceof Document) {
            return;
        }

        if ($document->state === DocumentState::Accepted || $document->storage_prefix === 'accepted') {
            return;
        }

        $acceptedPath = $pathResolver->acceptedPath($document->document_kind, $document->storage_key);
        $objectStorage->deleteIfExists($acceptedPath);
    }
}
