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
use App\Platform\DocumentVault\Models\Document;
use App\Platform\DocumentVault\Models\DocumentScan;
use App\Platform\DocumentVault\ScanVerdict;
use App\Platform\Outbox\Outbox;
use App\Platform\Outbox\OutboxClassification;
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
        return DB::transaction(function () use ($document): Document {
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

            if (! is_string($lockedDocument->checksum_sha256) || $lockedDocument->checksum_sha256 === '') {
                throw new LogicException('A document requires a checksum before promotion.');
            }

            $quarantinePath = $this->pathResolver->quarantinePath(
                $lockedDocument->document_kind,
                $lockedDocument->storage_key,
            );
            $acceptedPath = $this->pathResolver->acceptedPath(
                $lockedDocument->document_kind,
                $lockedDocument->storage_key,
            );

            $this->objectStorage->copy($quarantinePath, $acceptedPath);

            try {
                $acceptedChecksum = $this->objectStorage->checksum($acceptedPath);
            } catch (\Throwable $exception) {
                $this->removeUnverifiedAcceptedCopy($acceptedPath);

                throw $exception;
            }

            if (! hash_equals(strtolower($lockedDocument->checksum_sha256), strtolower($acceptedChecksum))) {
                $this->removeUnverifiedAcceptedCopy($acceptedPath);

                throw new RuntimeException('Accepted object checksum verification failed.');
            }

            // Quarantine is removed only after the accepted object has been
            // copied and independently verified.
            $this->objectStorage->delete($quarantinePath);
            $lockedDocument->fill(['storage_prefix' => 'accepted']);
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

            return $lockedDocument;
        });
    }

    private function removeUnverifiedAcceptedCopy(string $acceptedPath): void
    {
        try {
            $this->objectStorage->delete($acceptedPath);
        } catch (\Throwable) {
            // The original verification failure is the actionable result;
            // state remains SCANNING and quarantine is never removed.
        }
    }
}
