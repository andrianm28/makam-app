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
use App\Platform\DocumentVault\Contracts\MalwareScanner;
use App\Platform\DocumentVault\Contracts\ObjectStorage;
use App\Platform\DocumentVault\DocumentKind;
use App\Platform\DocumentVault\DocumentState;
use App\Platform\DocumentVault\Jobs\ScanDocumentJob;
use App\Platform\DocumentVault\Models\Document;
use App\Platform\DocumentVault\Models\DocumentScan;
use App\Platform\DocumentVault\ScanVerdict;
use App\Platform\DocumentVault\StoragePathPolicy;
use App\Platform\Outbox\Models\OutboxEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
    }

    public function test_a_document_with_scanning_disabled_records_clean_without_calling_the_scanner(): void
    {
        $document = $this->documentWithBytes($this->minimalPdf());
        $document->forceFill(['scanner_required' => false])->save();

        $scan = (new ScanDocument(
            new LocalFilesystemObjectStorage($this->root),
            $this->paths,
            new ThrowingScanner,
        ))->scan($document);

        $this->assertSame(ScanVerdict::Clean, $scan->verdict);
        $this->assertSame('not-required', $scan->scanner_name);
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
        $document = $this->documentWithBytes($this->minimalPdf());
        $storage = new LocalFilesystemObjectStorage($this->root);
        $this->cleanScan($document, $storage);

        $promoted = (new PromoteDocument($storage, $this->paths))->promote($document);

        $this->assertSame(DocumentState::Accepted, $promoted->fresh()->state);
        $this->assertSame('accepted', $promoted->fresh()->storage_prefix);
        $this->assertFileExists($this->root.'/KTP/accepted/'.$document->storage_key);
        $this->assertFileDoesNotExist($this->root.'/KTP/quarantine/'.$document->storage_key);
        $this->assertSame('DOCUMENT_ACCEPTED', AuditEvent::query()->where('action', 'DOCUMENT_ACCEPTED')->sole()->action);
        $outbox = OutboxEvent::query()->sole();
        $this->assertSame('document.accepted.v1', $outbox->event_name);
        $this->assertSame(['kind', 'state'], array_keys($outbox->payload));
        $this->assertSame("promote:{$document->id}", $outbox->idempotency_key);
    }

    public function test_a_checksum_mismatch_blocks_promotion_and_preserves_quarantine(): void
    {
        $document = $this->documentWithBytes($this->minimalPdf());
        $storage = new TamperingObjectStorage(new LocalFilesystemObjectStorage($this->root), $this->root);
        $this->cleanScan($document, $storage);

        $this->expectException(RuntimeException::class);

        try {
            (new PromoteDocument($storage, $this->paths))->promote($document);
        } finally {
            $this->assertSame(DocumentState::Scanning, $document->fresh()->state);
            $this->assertFileExists($this->root.'/KTP/quarantine/'.$document->storage_key);
            $this->assertFileDoesNotExist($this->root.'/KTP/accepted/'.$document->storage_key);
            $this->assertSame(0, OutboxEvent::query()->count());
        }
    }

    public function test_logical_retention_preserves_scan_evidence_and_requires_a_reason(): void
    {
        $document = $this->documentWithBytes($this->minimalPdf());
        $storage = new LocalFilesystemObjectStorage($this->root);
        $this->cleanScan($document, $storage);

        (new RetainDocument)->retain($document, 'Retention policy completed.');

        $retained = $document->fresh();
        $this->assertSame(DocumentState::Deleted, $retained->state);
        $this->assertNotNull($retained->retention_until);
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

    public function test_logical_retention_refuses_a_document_with_a_legal_hold_flag(): void
    {
        $document = $this->documentWithBytes($this->minimalPdf());
        $document->setRawAttributes($document->getAttributes() + ['legal_hold' => true]);

        $this->expectException(LogicException::class);

        (new RetainDocument)->retain($document, 'Retention policy completed.');
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

    public function read(string $path)
    {
        return $this->delegate->read($path);
    }

    public function checksum(string $path): string
    {
        return $this->delegate->checksum($path);
    }

    public function temporaryUrl(string $documentId, string $token): string
    {
        return $this->delegate->temporaryUrl($documentId, $token);
    }
}

final class ThrowingScanner implements MalwareScanner
{
    public function scan(DocumentKind $kind, $stream): ScanVerdict
    {
        throw new RuntimeException('scanner must not be called');
    }
}
