<?php

declare(strict_types=1);

namespace App\Platform\DocumentVault\Actions;

use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditOutcome;
use App\Platform\Audit\AuditSource;
use App\Platform\Audit\AuditSubject;
use App\Platform\DocumentVault\Contracts\MalwareScanner;
use App\Platform\DocumentVault\Contracts\ObjectStorage;
use App\Platform\DocumentVault\Contracts\StoragePathResolver;
use App\Platform\DocumentVault\DocumentState;
use App\Platform\DocumentVault\Jobs\ScanDocumentJob;
use App\Platform\DocumentVault\Models\Document;
use App\Platform\DocumentVault\Models\DocumentScan;
use App\Platform\DocumentVault\ScanVerdict;
use App\Platform\Outbox\OutboxQueueName;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Runs one quarantine scan and records its immutable evidence. A positive
 * scan only moves a document to SCANNING; promotion is a separate guarded
 * transaction.
 */
final readonly class ScanDocument
{
    private const int RETRY_BASE_SECONDS = 30;

    private const int RETRY_MAX_SECONDS = 300;

    public function __construct(
        private ObjectStorage $objectStorage,
        private StoragePathResolver $pathResolver,
        private MalwareScanner $scanner,
    ) {}

    public function scan(Document $document): DocumentScan
    {
        return DB::transaction(function () use ($document): DocumentScan {
            $lockedDocument = Document::query()->lockForUpdate()->find($document->getKey());

            if (! $lockedDocument instanceof Document) {
                throw (new ModelNotFoundException)->setModel(Document::class, [$document->getKey()]);
            }

            if (! in_array($lockedDocument->state, [DocumentState::Quarantined, DocumentState::Scanning], true)) {
                throw new \LogicException(
                    "Document {$lockedDocument->getKey()} cannot be scanned from state {$lockedDocument->state->value}.",
                );
            }

            $latest = DocumentScan::query()
                ->where('document_id', $lockedDocument->getKey())
                ->orderByDesc('attempt')
                ->orderByDesc('id')
                ->first();
            $attempt = ($latest?->attempt ?? 0) + 1;
            $previousVerdict = $latest?->verdict;

            if ($lockedDocument->state === DocumentState::Quarantined) {
                $lockedDocument->transitionTo(DocumentState::Scanning);
            }

            [$verdict, $scannerName, $engineVersion, $evidence] = $this->runScan($lockedDocument);

            $scan = DocumentScan::recordAttempt(
                document: $lockedDocument,
                scannerName: $scannerName,
                scannerEngineVersion: $engineVersion,
                verdict: $verdict,
                evidence: $evidence,
                attempt: $attempt,
            );

            $this->applyVerdict($lockedDocument, $verdict);

            if ($previousVerdict !== $verdict) {
                Audit::record(
                    action: 'DOCUMENT_SCAN',
                    subject: new AuditSubject('document', $lockedDocument->getKey()),
                    outcome: $verdict === ScanVerdict::Clean ? AuditOutcome::Allowed : AuditOutcome::Denied,
                    actorRef: null,
                    actorRole: 'system',
                    source: AuditSource::Job,
                );
            }

            if ($verdict === ScanVerdict::Error) {
                $this->queueRetry($lockedDocument, $attempt);
            }

            return $scan;
        });
    }

    /**
     * @return array{0: ScanVerdict, 1: string, 2: string, 3: array<string, mixed>}
     */
    private function runScan(Document $document): array
    {
        if (! $document->scanner_required) {
            return [
                ScanVerdict::Clean,
                'not-required',
                'not-applicable',
                ['scanner_required' => false],
            ];
        }

        $stream = null;

        try {
            $stream = $this->objectStorage->read(
                $this->pathResolver->quarantinePath($document->document_kind, $document->storage_key),
            );
            $verdict = $this->scanner->scan($document->document_kind, $stream);

            return [
                $verdict,
                class_basename($this->scanner),
                (string) config('document-vault.scanner_engine_version', 'unknown'),
                $this->evidenceFor($verdict),
            ];
        } catch (Throwable) {
            return [
                ScanVerdict::Error,
                class_basename($this->scanner),
                (string) config('document-vault.scanner_engine_version', 'unknown'),
                ['reason' => 'scanner_unavailable'],
            ];
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function evidenceFor(ScanVerdict $verdict): array
    {
        return match ($verdict) {
            ScanVerdict::Clean => ['result' => 'clean'],
            ScanVerdict::Infected => ['reason' => 'malware_detected'],
            ScanVerdict::Suspicious => [
                'suspicious' => true,
                'reason' => 'manual_review_required',
            ],
            ScanVerdict::Error => ['reason' => 'scanner_unavailable'],
        };
    }

    private function applyVerdict(Document $document, ScanVerdict $verdict): void
    {
        if ($verdict === ScanVerdict::Infected) {
            $document->transitionTo(DocumentState::Rejected);
        }
    }

    private function queueRetry(Document $document, int $attempt): void
    {
        $delay = min(
            self::RETRY_MAX_SECONDS,
            self::RETRY_BASE_SECONDS * (2 ** min($attempt - 1, 4)),
        );
        $documentId = (string) $document->getKey();

        DB::afterCommit(function () use ($documentId, $delay): void {
            ScanDocumentJob::dispatch($documentId)
                ->onQueue(OutboxQueueName::Media->value)
                ->delay(now()->addSeconds($delay));
        });
    }
}
