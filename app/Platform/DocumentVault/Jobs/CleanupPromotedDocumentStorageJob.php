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

        try {
            DB::transaction(function () use ($cleanup, $objectStorage, $pathResolver): void {
                $cleanupReference = DocumentStorageCleanup::query()->find($cleanup->getKey());

                if (! $cleanupReference instanceof DocumentStorageCleanup) {
                    return;
                }

                $document = Document::query()
                    ->lockForUpdate()
                    ->find($cleanupReference->document_id);

                if (! $document instanceof Document) {
                    throw new \LogicException('Promotion cleanup document is missing.');
                }

                // Promotion locks the document before inserting its marker;
                // take locks in the same order here to avoid a promotion/
                // cleanup deadlock under PostgreSQL.
                $lockedCleanup = DocumentStorageCleanup::query()
                    ->whereKey($cleanup->getKey())
                    ->whereNull('completed_at')
                    ->lockForUpdate()
                    ->first();

                if (! $lockedCleanup instanceof DocumentStorageCleanup) {
                    return;
                }

                $this->assertMarkerMatchesDocument($lockedCleanup, $document);

                if (
                    $lockedCleanup->operation !== DocumentStorageCleanup::QUARANTINE_DELETE
                    || $document->storage_prefix !== 'accepted'
                    || ! in_array($document->state, [DocumentState::Accepted, DocumentState::Deleted, DocumentState::Expired], true)
                ) {
                    throw new \LogicException('Promotion cleanup is waiting for an accepted document state.');
                }

                $quarantinePath = $pathResolver->quarantinePath($document->document_kind, $document->storage_key);
                $objectStorage->deleteIfExists($quarantinePath);
                $lockedCleanup->markCompleted();
            });
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

        DB::transaction(function () use ($document, $objectStorage, $pathResolver): void {
            $lockedDocument = Document::query()->lockForUpdate()->find($document->getKey());

            if (! $lockedDocument instanceof Document) {
                return;
            }

            $pendingMarker = DocumentStorageCleanup::query()
                ->where('document_id', $lockedDocument->getKey())
                ->whereNull('completed_at')
                ->lockForUpdate()
                ->first();

            $checksum = $lockedDocument->checksum_sha256;
            $storageKey = $lockedDocument->storage_key;
            $documentKind = $lockedDocument->document_kind;

            if (
                $lockedDocument->state === DocumentState::Accepted
                || $lockedDocument->storage_prefix === 'accepted'
                || ! is_string($checksum)
                || $checksum === ''
            ) {
                return;
            }

            if ($pendingMarker instanceof DocumentStorageCleanup) {
                $this->assertMarkerMatchesDocument($pendingMarker, $lockedDocument);
            }

            $acceptedPath = $pathResolver->acceptedPath($documentKind, $storageKey);
            $objectStorage->deleteIfExists($acceptedPath);
        });
    }

    private function assertMarkerMatchesDocument(DocumentStorageCleanup $marker, Document $document): void
    {
        if (
            (string) $marker->document_id !== (string) $document->getKey()
            ||
            $marker->document_kind !== $document->document_kind->value
            || $marker->storage_key !== $document->storage_key
            || ! is_string($marker->checksum_sha256)
            || ! is_string($document->checksum_sha256)
            || ! hash_equals(strtolower($marker->checksum_sha256), strtolower($document->checksum_sha256))
        ) {
            throw new \LogicException('Promotion cleanup identity no longer matches the document.');
        }
    }
}
