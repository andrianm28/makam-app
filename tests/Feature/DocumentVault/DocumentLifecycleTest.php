<?php

declare(strict_types=1);

namespace Tests\Feature\DocumentVault;

use App\Platform\Audit\AuditOutcome;
use App\Platform\Audit\Exceptions\AuditReasonRequiredException;
use App\Platform\Audit\Models\AuditEvent;
use App\Platform\DocumentVault\Actions\PromoteDocument;
use App\Platform\DocumentVault\Actions\RetainDocument;
use App\Platform\DocumentVault\Actions\ScanDocument;
use App\Platform\DocumentVault\Adapters\LocalFilesystemObjectStorage;
use App\Platform\DocumentVault\Adapters\MockScanner;
use App\Platform\DocumentVault\Contracts\ObjectStorage;
use App\Platform\DocumentVault\DocumentKind;
use App\Platform\DocumentVault\DocumentState;
use App\Platform\DocumentVault\Jobs\CleanupPromotedDocumentStorageJob;
use App\Platform\DocumentVault\Jobs\ReconcileDocumentStorageCleanupJob;
use App\Platform\DocumentVault\Jobs\ScanDocumentJob;
use App\Platform\DocumentVault\Models\Document;
use App\Platform\DocumentVault\Models\DocumentScan;
use App\Platform\DocumentVault\Models\DocumentStorageCleanup;
use App\Platform\DocumentVault\ScanVerdict;
use App\Platform\DocumentVault\StoragePathPolicy;
use App\Platform\Outbox\Models\OutboxEvent;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use LogicException;
use RuntimeException;
use Tests\TestCase;

final class DocumentLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private string $root;

    private StoragePathPolicy $paths;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().'/document-vault-lifecycle-'.Str::random(12);
        $this->paths = new StoragePathPolicy;
        config(['document-vault.scanner_outage' => false]);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);

        parent::tearDown();
    }

    public function test_a_clean_scan_records_evidence_and_leaves_the_document_pending_promotion(): void
    {
        $document = $this->documentWithBytes($this->minimalPdf());

        $scan = (new ScanDocument(
            new LocalFilesystemObjectStorage($this->root),
            $this->paths,
            new MockScanner,
        ))->scan($document);

        $this->assertSame(ScanVerdict::Clean, $scan->verdict);
        $this->assertSame(1, $scan->attempt);
        $this->assertSame($document->checksum_sha256, $scan->checksum_sha256);
        $this->assertSame(DocumentState::Scanning, $document->fresh()->state);
        $this->assertSame('MockScanner', $scan->scanner_name);
        $this->assertNotSame('', $scan->scanner_engine_version);
        $this->assertSame(AuditOutcome::Allowed->value, AuditEvent::query()->sole()->outcome);
        $this->assertSame('DOCUMENT_SCAN', AuditEvent::query()->sole()->action);
    }

    public function test_scanner_error_keeps_scanning_never_accepts_and_requeues_on_media_with_bounded_delay(): void
    {
        Queue::fake();
        config(['document-vault.scanner_outage' => true]);

        $document = $this->documentWithBytes($this->minimalPdf());

        $scan = (new ScanDocument(
            new LocalFilesystemObjectStorage($this->root),
            $this->paths,
            new MockScanner,
        ))->scan($document);

        $this->assertSame(ScanVerdict::Error, $scan->verdict);
        $this->assertSame(DocumentState::Scanning, $document->fresh()->state);
        $this->assertNotSame(DocumentState::Accepted, $document->fresh()->state);
        $this->assertSame(AuditOutcome::Denied->value, AuditEvent::query()->sole()->outcome);
        Queue::assertPushedOn('media', ScanDocumentJob::class, function (ScanDocumentJob $job): bool {
            return $job->documentId !== '' && $job->delay !== null;
        });
    }

    public function test_infected_scan_rejects_and_suspicious_scan_stays_pending_with_a_review_flag(): void
    {
        $infected = $this->documentWithBytes(
            'X5O!P%@AP[4\\PZX54(P^)7CC)7}$EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*',
        );

        $scanner = new ScanDocument(
            new LocalFilesystemObjectStorage($this->root),
            $this->paths,
            new MockScanner,
        );

        $infectedScan = $scanner->scan($infected);

        $this->assertSame(ScanVerdict::Infected, $infectedScan->verdict);
        $this->assertSame(DocumentState::Rejected, $infected->fresh()->state);

        $suspiciousBytes = str_repeat('suspicious', intdiv(DocumentKind::Ktp->maxSizeBytes(), 10) + 1);
        $suspicious = $this->documentWithBytes($suspiciousBytes, 'suspicious');
        $suspiciousScan = $scanner->scan($suspicious);

        $this->assertSame(ScanVerdict::Suspicious, $suspiciousScan->verdict);
        $this->assertTrue($suspiciousScan->evidence['suspicious']);
        $this->assertSame(DocumentState::Scanning, $suspicious->fresh()->state);
        $this->assertSame(2, AuditEvent::query()->where('action', 'DOCUMENT_SCAN')->where('outcome', 'denied')->count());
    }

    public function test_a_persisted_false_scanner_flag_cannot_bypass_a_restricted_kind_scan(): void
    {
        Queue::fake();
        $document = $this->documentWithBytes($this->minimalPdf());
        DB::table('documents')->where('id', $document->id)->update(['scanner_required' => false]);
        config(['document-vault.scanner_outage' => true]);

        $scan = (new ScanDocument(
            new LocalFilesystemObjectStorage($this->root),
            $this->paths,
            new MockScanner,
        ))->scan($document);

        $this->assertSame(ScanVerdict::Error, $scan->verdict);
        $this->assertSame(DocumentState::Scanning, $document->fresh()->state);
    }

    public function test_promotion_requires_the_latest_clean_verdict(): void
    {
        $document = $this->documentWithBytes($this->minimalPdf());
        $document->transitionTo(DocumentState::Scanning);

        $this->expectException(LogicException::class);

        (new PromoteDocument(
            new LocalFilesystemObjectStorage($this->root),
            $this->paths,
        ))->promote($document);
    }

    public function test_promotion_copies_verifies_then_deletes_quarantine_and_records_the_transition(): void
    {
        Queue::fake();
        $document = $this->documentWithBytes($this->minimalPdf());
        $storage = new LocalFilesystemObjectStorage($this->root);
        $this->cleanScan($document, $storage);

        $promoted = (new PromoteDocument($storage, $this->paths))->promote($document);

        $this->assertSame(DocumentState::Accepted, $promoted->fresh()->state);
        $this->assertSame('accepted', $promoted->fresh()->storage_prefix);
        $this->assertFileExists($this->root.'/KTP/accepted/'.$document->storage_key);
        $this->assertFileExists($this->root.'/KTP/quarantine/'.$document->storage_key);
        $this->assertSame('DOCUMENT_ACCEPTED', AuditEvent::query()->where('action', 'DOCUMENT_ACCEPTED')->sole()->action);
        $outbox = OutboxEvent::query()->sole();
        $this->assertSame('document.accepted.v1', $outbox->event_name);
        $this->assertSame(['kind', 'state'], array_keys($outbox->payload));
        $this->assertSame("promote:{$document->id}", $outbox->idempotency_key);
        $cleanup = DocumentStorageCleanup::query()->sole();
        $this->assertSame($document->id, $cleanup->document_id);
        $this->assertSame('QUARANTINE_DELETE', $cleanup->operation);
        $this->assertSame($document->document_kind->value, $cleanup->document_kind);
        $this->assertSame($document->storage_key, $cleanup->storage_key);
        $this->assertSame($document->checksum_sha256, $cleanup->checksum_sha256);
        $this->assertNull($cleanup->completed_at);

        (new CleanupPromotedDocumentStorageJob($document->id))->handle($storage, $this->paths);

        $this->assertFileDoesNotExist($this->root.'/KTP/quarantine/'.$document->storage_key);
        $this->assertNotNull($cleanup->fresh()->completed_at);
    }

    public function test_reconciliation_discovers_a_committed_pending_cleanup_without_the_original_worker(): void
    {
        Queue::fake();
        $document = $this->documentWithBytes($this->minimalPdf());
        $storage = new LocalFilesystemObjectStorage($this->root);
        $this->cleanScan($document, $storage);
        (new PromoteDocument($storage, $this->paths))->promote($document);

        (new ReconcileDocumentStorageCleanupJob)->handle($storage, $this->paths);

        $this->assertFileDoesNotExist($this->root.'/KTP/quarantine/'.$document->storage_key);
        $this->assertFileExists($this->root.'/KTP/accepted/'.$document->storage_key);
        $this->assertNotNull(DocumentStorageCleanup::query()->sole()->completed_at);
    }

    public function test_cleanup_does_not_delete_quarantine_before_locked_accepted_state(): void
    {
        $document = $this->documentWithBytes($this->minimalPdf());
        $storage = new LocalFilesystemObjectStorage($this->root);
        DocumentStorageCleanup::recordPending($document);

        $this->expectException(LogicException::class);

        try {
            (new CleanupPromotedDocumentStorageJob($document->id))->handle($storage, $this->paths);
        } finally {
            $this->assertFileExists($this->root.'/KTP/quarantine/'.$document->storage_key);
            $this->assertNull(DocumentStorageCleanup::query()->sole()->completed_at);
        }
    }

    public function test_a_checksum_mismatch_blocks_promotion_and_preserves_quarantine(): void
    {
        Queue::fake();
        $document = $this->documentWithBytes($this->minimalPdf());
        $storage = new TamperingObjectStorage(new LocalFilesystemObjectStorage($this->root), $this->root);
        $this->cleanScan($document, $storage);

        $this->expectException(RuntimeException::class);

        try {
            (new PromoteDocument($storage, $this->paths))->promote($document);
        } finally {
            $this->assertSame(DocumentState::Scanning, $document->fresh()->state);
            $this->assertFileExists($this->root.'/KTP/quarantine/'.$document->storage_key);
            $this->assertFileExists($this->root.'/KTP/accepted/'.$document->storage_key);
            $this->assertSame(0, OutboxEvent::query()->count());

            (new CleanupPromotedDocumentStorageJob($document->id, reconcileAcceptedCopy: true))
                ->handle($storage, $this->paths);

            $this->assertFileDoesNotExist($this->root.'/KTP/accepted/'.$document->storage_key);
        }
    }

    public function test_a_checksum_mismatch_between_document_and_scan_blocks_promotion(): void
    {
        $document = $this->documentWithBytes($this->minimalPdf());
        $storage = new LocalFilesystemObjectStorage($this->root);
        $this->cleanScan($document, $storage);
        DB::table('documents')->where('id', $document->id)->update([
            'checksum_sha256' => hash('sha256', 'different bytes'),
        ]);

        $this->expectException(LogicException::class);

        (new PromoteDocument($storage, $this->paths))->promote($document);
    }

    public function test_a_clean_scan_followed_by_checksum_drift_becomes_error_and_cannot_promote(): void
    {
        Queue::fake();
        $document = $this->documentWithBytes($this->minimalPdf());
        $storage = new LocalFilesystemObjectStorage($this->root);
        $this->cleanScan($document, $storage);
        DB::table('documents')->where('id', $document->id)->update([
            'checksum_sha256' => hash('sha256', 'different bytes'),
        ]);

        $scan = (new ScanDocument($storage, $this->paths, new MockScanner))->scan($document);

        $this->assertSame(ScanVerdict::Error, $scan->verdict);
        $this->assertSame(AuditOutcome::Denied->value, AuditEvent::query()->latest('id')->value('outcome'));
        $this->expectException(LogicException::class);

        (new PromoteDocument($storage, $this->paths))->promote($document);
    }

    public function test_document_scan_model_rejects_instance_mutation(): void
    {
        $document = $this->documentWithBytes($this->minimalPdf());
        $scan = (new ScanDocument(
            new LocalFilesystemObjectStorage($this->root),
            $this->paths,
            new MockScanner,
        ))->scan($document);

        $this->expectException(LogicException::class);

        $scan->update(['evidence' => ['changed' => true]]);
    }

    public function test_a_promotion_rollback_keeps_quarantine_and_schedules_accepted_reconciliation(): void
    {
        Queue::fake();
        $document = $this->documentWithBytes($this->minimalPdf());
        $storage = new LocalFilesystemObjectStorage($this->root);
        $this->cleanScan($document, $storage);
        OutboxEvent::query()->create([
            'event_name' => 'fixture.existing.v1',
            'event_version' => 1,
            'aggregate_type' => 'fixture',
            'aggregate_id' => $document->id,
            'payload' => ['state' => 'fixture'],
            'classification' => 'INTERNAL',
            'occurred_at' => now(),
            'available_at' => now(),
            'attempt_count' => 0,
            'idempotency_key' => "promote:{$document->id}",
        ]);

        $this->expectException(QueryException::class);

        try {
            (new PromoteDocument($storage, $this->paths))->promote($document);
        } finally {
            $this->assertSame(DocumentState::Scanning, $document->fresh()->state);
            $this->assertFileExists($this->root.'/KTP/quarantine/'.$document->storage_key);
            $this->assertSame(0, DocumentStorageCleanup::query()->count());
            Queue::assertPushed(CleanupPromotedDocumentStorageJob::class, function (CleanupPromotedDocumentStorageJob $job): bool {
                return $job->reconcileAcceptedCopy;
            });
        }
    }

    public function test_a_failed_copy_schedules_cleanup_without_deleting_quarantine(): void
    {
        Queue::fake();
        $document = $this->documentWithBytes($this->minimalPdf());
        $storage = new PartialCopyFailureStorage(new LocalFilesystemObjectStorage($this->root), $this->root);
        $this->cleanScan($document, $storage);

        $this->expectException(RuntimeException::class);

        try {
            (new PromoteDocument($storage, $this->paths))->promote($document);
        } finally {
            $this->assertSame(DocumentState::Scanning, $document->fresh()->state);
            $this->assertFileExists($this->root.'/KTP/quarantine/'.$document->storage_key);
            Queue::assertPushed(CleanupPromotedDocumentStorageJob::class);
        }
    }

    public function test_quarantine_cleanup_failure_is_left_for_job_retry(): void
    {
        Queue::fake();
        $document = $this->documentWithBytes($this->minimalPdf());
        $storage = new LocalFilesystemObjectStorage($this->root);
        $this->cleanScan($document, $storage);
        (new PromoteDocument($storage, $this->paths))->promote($document);
        $failingStorage = new DeleteFailureStorage($storage);

        $this->expectException(RuntimeException::class);

        try {
            (new CleanupPromotedDocumentStorageJob($document->id))->handle($failingStorage, $this->paths);
        } finally {
            $this->assertFileExists($this->root.'/KTP/quarantine/'.$document->storage_key);
            $marker = DocumentStorageCleanup::query()->sole();
            $this->assertNull($marker->completed_at);
            $this->assertSame(1, $marker->attempt_count);
            $this->assertNotNull($marker->last_error);
        }
    }

    public function test_logical_retention_preserves_scan_evidence_and_requires_a_reason(): void
    {
        $document = $this->documentWithBytes($this->minimalPdf());
        $storage = new LocalFilesystemObjectStorage($this->root);
        $this->cleanScan($document, $storage);

        config(['document-vault.retention_days.KTP' => 30]);
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-10 12:00:00'));

        try {
            (new RetainDocument)->retain($document, 'Retention policy completed.');
        } finally {
            CarbonImmutable::setTestNow();
        }

        $retained = $document->fresh();
        $this->assertSame(DocumentState::Deleted, $retained->state);
        $this->assertSame('2026-09-09 12:00:00', $retained->retention_until->format('Y-m-d H:i:s'));
        $this->assertSame(1, DocumentScan::query()->count());
        $this->assertSame(1, AuditEvent::query()->where('action', 'DOCUMENT_DELETE')->count());
        $this->assertSame(1, OutboxEvent::query()->where('event_name', 'document.deleted.v1')->count());
        $this->assertFileExists($this->root.'/KTP/quarantine/'.$document->storage_key);

        $second = $this->documentWithBytes($this->minimalPdf(), 'second');
        $this->expectException(AuditReasonRequiredException::class);

        try {
            (new RetainDocument)->retain($second, '');
        } finally {
            $this->assertSame(DocumentState::Quarantined, $second->fresh()->state);
        }
    }

    public function test_logical_retention_refuses_a_persisted_legal_or_evidence_hold(): void
    {
        $document = $this->documentWithBytes($this->minimalPdf());
        DB::table('documents')->where('id', $document->id)->update(['legal_hold' => true]);

        $this->expectException(LogicException::class);

        try {
            (new RetainDocument)->retain($document, 'Retention policy completed.');
        } finally {
            $this->assertSame(DocumentState::Quarantined, $document->fresh()->state);
        }
    }

    private function cleanScan(Document $document, ObjectStorage $storage): void
    {
        (new ScanDocument($storage, $this->paths, new MockScanner))->scan($document);
    }

    private function documentWithBytes(string $bytes, string $keySuffix = ''): Document
    {
        $storage = new LocalFilesystemObjectStorage($this->root);
        $storageKey = 'document-'.$keySuffix.Str::random(8);
        $stream = fopen('php://temp', 'r+b');
        fwrite($stream, $bytes);
        rewind($stream);
        $storage->put($this->paths->quarantinePath(DocumentKind::Ktp, $storageKey), $stream);
        fclose($stream);

        return Document::createQuarantined([
            'document_kind' => DocumentKind::Ktp,
            'owner_type' => 'booking_draft',
            'owner_id' => 'draft-'.Str::random(8),
            'original_filename' => 'document.pdf',
            'storage_prefix' => 'quarantine',
            'storage_key' => $storageKey,
            'size_bytes' => strlen($bytes),
            'mime_declared' => 'application/pdf',
            'mime_verified' => 'application/pdf',
            'checksum_sha256' => hash('sha256', $bytes),
            'scanner_required' => true,
        ]);
    }

    private function minimalPdf(): string
    {
        return "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF";
    }

    private function removeDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        foreach (scandir($directory) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $directory.'/'.$item;

            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($directory);
    }
}

final class TamperingObjectStorage implements ObjectStorage
{
    public function __construct(
        private readonly LocalFilesystemObjectStorage $delegate,
        private readonly string $root,
    ) {}

    public function put(string $path, $stream): void
    {
        $this->delegate->put($path, $stream);
    }

    public function copy(string $sourcePath, string $destinationPath): void
    {
        $this->delegate->copy($sourcePath, $destinationPath);
        file_put_contents($this->root.'/'.$destinationPath, 'tampered');
    }

    public function delete(string $path): void
    {
        $this->delegate->delete($path);
    }

    public function deleteIfExists(string $path): void
    {
        $this->delegate->deleteIfExists($path);
    }

    public function read(string $path)
    {
        return $this->delegate->read($path);
    }

    public function checksum(string $path): string
    {
        return $this->delegate->checksum($path);
    }
}

final class PartialCopyFailureStorage implements ObjectStorage
{
    public function __construct(
        private readonly LocalFilesystemObjectStorage $delegate,
        private readonly string $root,
    ) {}

    public function put(string $path, $stream): void
    {
        $this->delegate->put($path, $stream);
    }

    public function copy(string $sourcePath, string $destinationPath): void
    {
        $this->delegate->copy($sourcePath, $destinationPath);
        file_put_contents($this->root.'/'.$destinationPath, 'partial');
        throw new RuntimeException('simulated copy failure');
    }

    public function delete(string $path): void
    {
        $this->delegate->delete($path);
    }

    public function deleteIfExists(string $path): void
    {
        $this->delegate->deleteIfExists($path);
    }

    public function read(string $path)
    {
        return $this->delegate->read($path);
    }

    public function checksum(string $path): string
    {
        return $this->delegate->checksum($path);
    }
}

final class DeleteFailureStorage implements ObjectStorage
{
    public function __construct(private readonly ObjectStorage $delegate) {}

    public function put(string $path, $stream): void
    {
        $this->delegate->put($path, $stream);
    }

    public function copy(string $sourcePath, string $destinationPath): void
    {
        $this->delegate->copy($sourcePath, $destinationPath);
    }

    public function delete(string $path): void
    {
        $this->delegate->delete($path);
    }

    public function deleteIfExists(string $path): void
    {
        throw new RuntimeException('simulated cleanup delete failure');
    }

    public function read(string $path)
    {
        return $this->delegate->read($path);
    }

    public function checksum(string $path): string
    {
        return $this->delegate->checksum($path);
    }
}
