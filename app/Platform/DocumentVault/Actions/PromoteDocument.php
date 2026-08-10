<?php

declare(strict_types=1);

namespace App\Platform\DocumentVault\Actions;

use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditOutcome;
use App\Platform\Audit\AuditSource;
use App\Platform\Audit\AuditSubject;
use App\Platform\DocumentVault\Contracts\ObjectStorage;
use App\Platform\DocumentVault\Contracts\StoragePathResolver;
use App\Platform\DocumentVault\DocumentKind;
use App\Platform\DocumentVault\DocumentState;
use App\Platform\DocumentVault\Jobs\CleanupPromotedDocumentStorageJob;
use App\Platform\DocumentVault\Models\Document;
use App\Platform\DocumentVault\Models\DocumentScan;
use App\Platform\DocumentVault\ScanVerdict;
use App\Platform\Outbox\Outbox;
use App\Platform\Outbox\OutboxClassification;
use App\Platform\Outbox\OutboxQueueName;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use LogicException;
use RuntimeException;

/**
 * Promotes a clean, scanning document with copy-then-verify-then-delete
 * ordering. Storage and database writes are kept in the same application
 * transaction; a checksum failure remains fail-closed and leaves quarantine
 * intact.
 */
final readonly class PromoteDocument
{
    public function __construct(
        private ObjectStorage $objectStorage,
        private StoragePathResolver $pathResolver,
    ) {}

    public function promote(Document $document): Document
    {
        $copyAttempted = false;
        $documentId = (string) $document->getKey();

        try {
            return DB::transaction(function () use ($document, &$copyAttempted, $documentId): Document {
                $lockedDocument = Document::query()->lockForUpdate()->find($document->getKey());

                if (! $lockedDocument instanceof Document) {
                    throw (new ModelNotFoundException)->setModel(Document::class, [$document->getKey()]);
                }

                if ($lockedDocument->state !== DocumentState::Scanning) {
                    throw new LogicException('Only a scanning document may be promoted.');
                }

                $latestScan = DocumentScan::query()
                    ->where('document_id', $lockedDocument->getKey())
                    ->orderByDesc('attempt')
                    ->orderByDesc('id')
                    ->first();

                if ($latestScan === null || $latestScan->verdict !== ScanVerdict::Clean) {
                    throw new LogicException('A document requires a latest clean scan before promotion.');
                }

                if (
                    ! is_string($lockedDocument->checksum_sha256)
                    || $lockedDocument->checksum_sha256 === ''
                    || ! is_string($latestScan->checksum_sha256)
                    || ! hash_equals(
                        strtolower($lockedDocument->checksum_sha256),
                        strtolower($latestScan->checksum_sha256),
                    )
                ) {
                    throw new LogicException('The latest clean scan does not match the document checksum.');
                }

                $quarantinePath = $this->pathResolver->quarantinePath(
                    $lockedDocument->document_kind,
                    $lockedDocument->storage_key,
                );
                $acceptedPath = $this->pathResolver->acceptedPath(
                    $lockedDocument->document_kind,
                    $lockedDocument->storage_key,
                );

                // Mark the attempt before the provider call so a partial
                // destination is reconciled if copy() throws.
                $copyAttempted = true;
                $this->objectStorage->copy($quarantinePath, $acceptedPath);
                $acceptedChecksum = $this->objectStorage->checksum($acceptedPath);

                if (! hash_equals(strtolower($lockedDocument->checksum_sha256), strtolower($acceptedChecksum))) {
                    throw new RuntimeException('Accepted object checksum verification failed.');
                }

                // Quarantine cleanup is deliberately registered after the
                // state, audit, and outbox writes so rollback cannot delete
                // the only known source bytes.
                $lockedDocument->promote();

                Audit::record(
                    action: 'DOCUMENT_ACCEPTED',
                    subject: new AuditSubject('document', $lockedDocument->getKey()),
                    outcome: AuditOutcome::Allowed,
                    actorRef: null,
                    actorRole: 'system',
                    source: AuditSource::Job,
                    reason: $lockedDocument->document_kind === DocumentKind::DeathCertificate
                        ? 'accepted after clean scan'
                        : null,
                );

                Outbox::record(
                    eventName: 'document.accepted.v1',
                    eventVersion: 1,
                    aggregateType: 'document',
                    aggregateId: $lockedDocument->getKey(),
                    data: [
                        'kind' => $lockedDocument->document_kind->value,
                        'state' => 'accepted',
                    ],
                    classification: OutboxClassification::Confidential,
                    idempotencyKey: "promote:{$lockedDocument->getKey()}",
                );

                DB::afterCommit(function () use ($documentId): void {
                    CleanupPromotedDocumentStorageJob::dispatch($documentId)
                        ->onQueue(OutboxQueueName::Media->value);
                });

                return $lockedDocument;
            });
        } catch (\Throwable $exception) {
            if ($copyAttempted) {
                $reconcileAcceptedCopy = true;

                try {
                    $persistedDocument = Document::query()->find($documentId);
                    $reconcileAcceptedCopy = ! (
                        $persistedDocument instanceof Document
                        && $persistedDocument->storage_prefix === 'accepted'
                    );
                } catch (\Throwable) {
                    // Preserve the original promotion failure; the fallback
                    // remains the safer accepted-copy reconciliation.
                }

                CleanupPromotedDocumentStorageJob::dispatch(
                    documentId: $documentId,
                    reconcileAcceptedCopy: $reconcileAcceptedCopy,
                )->onQueue(OutboxQueueName::Media->value);
            }

            throw $exception;
        }
    }
}
