<?php

declare(strict_types=1);

namespace App\Platform\DocumentVault\Jobs;

use App\Platform\DocumentVault\Contracts\ObjectStorage;
use App\Platform\DocumentVault\Contracts\StoragePathResolver;
use App\Platform\DocumentVault\DocumentState;
use App\Platform\DocumentVault\Models\Document;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Idempotent post-transaction storage cleanup. A committed promotion removes
 * quarantine; a rolled-back promotion removes only the uncommitted accepted
 * copy. Neither path can delete the source before the database commit.
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
        public readonly string $documentId,
        public readonly bool $reconcileAcceptedCopy = false,
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
        $document = Document::query()->find($this->documentId);

        if (! $document instanceof Document) {
            return;
        }

        $acceptedPath = $pathResolver->acceptedPath($document->document_kind, $document->storage_key);
        $quarantinePath = $pathResolver->quarantinePath($document->document_kind, $document->storage_key);

        if ($this->reconcileAcceptedCopy) {
            if ($document->state === DocumentState::Accepted || $document->storage_prefix === 'accepted') {
                return;
            }

            $objectStorage->deleteIfExists($acceptedPath);

            return;
        }

        if ($document->storage_prefix !== 'accepted') {
            return;
        }

        $objectStorage->deleteIfExists($quarantinePath);
    }
}
