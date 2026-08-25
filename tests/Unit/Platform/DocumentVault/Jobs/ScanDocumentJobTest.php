<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\DocumentVault\Jobs;

use App\Platform\Audit\Models\AuditEvent;
use App\Platform\DocumentVault\Actions\ScanDocument;
use App\Platform\DocumentVault\Adapters\LocalFilesystemObjectStorage;
use App\Platform\DocumentVault\Adapters\MockScanner;
use App\Platform\DocumentVault\DocumentKind;
use App\Platform\DocumentVault\DocumentState;
use App\Platform\DocumentVault\Jobs\ScanDocumentJob;
use App\Platform\DocumentVault\Models\Document;
use App\Platform\DocumentVault\Models\DocumentScan;
use App\Platform\DocumentVault\ScanVerdict;
use App\Platform\DocumentVault\StoragePathPolicy;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The queue job carries a document id and delegates to the Task 5 scan
 * Action; storage/scanner integration is covered by the lifecycle feature
 * tests.
 *
 * The consumer-level state guard (AGENTS.md idempotent-consumer discipline)
 * is asserted here: a document that is no longer scannable — already
 * ACCEPTED, the exact race with the synchronous issuance pipeline — makes
 * the job a no-op instead of a failed/retried job, while still-scannable
 * states (QUARANTINED, SCANNING) keep scanning.
 */
final class ScanDocumentJobTest extends TestCase
{
    use RefreshDatabase;

    private string $root;

    private StoragePathPolicy $paths;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().'/document-vault-scan-job-'.Str::random(12);
        $this->paths = new StoragePathPolicy;
        config(['document-vault.scanner_outage' => false]);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);

        parent::tearDown();
    }

    public function test_it_is_queueable_and_carries_the_document_id(): void
    {
        $job = new ScanDocumentJob('11111111-1111-1111-1111-111111111111');

        $this->assertInstanceOf(ShouldQueue::class, $job);
        $this->assertSame('11111111-1111-1111-1111-111111111111', $job->documentId);
    }

    public function test_handle_requires_a_persisted_document(): void
    {
        $job = new ScanDocumentJob('11111111-1111-1111-1111-111111111111');

        $this->expectException(ModelNotFoundException::class);

        $job->handle(app(ScanDocument::class));
    }

    public function test_handle_noops_on_an_already_accepted_document_without_changing_state(): void
    {
        $document = $this->acceptedDocument();

        $job = new ScanDocumentJob($document->id);
        $job->handle($this->scanDocument());

        $this->assertSame(DocumentState::Accepted, $document->fresh()->state);
        $this->assertSame('accepted', $document->fresh()->storage_prefix);
        $this->assertSame(0, DocumentScan::query()->count());
        $this->assertSame(0, AuditEvent::query()->where('action', 'DOCUMENT_SCAN')->count());
    }

    public function test_handle_scans_a_quarantined_document(): void
    {
        $document = $this->documentWithBytes($this->minimalPdf());

        $job = new ScanDocumentJob($document->id);
        $job->handle($this->scanDocument());

        $scan = DocumentScan::query()->sole();
        $this->assertSame(ScanVerdict::Clean, $scan->verdict);
        $this->assertSame(1, $scan->attempt);
        $this->assertSame($document->checksum_sha256, $scan->checksum_sha256);
        $this->assertSame(DocumentState::Scanning, $document->fresh()->state);
    }

    public function test_handle_keeps_scanning_a_scanning_document(): void
    {
        $document = $this->documentWithBytes($this->minimalPdf());
        $document->transitionTo(DocumentState::Scanning);

        $job = new ScanDocumentJob($document->id);
        $job->handle($this->scanDocument());

        $this->assertSame(DocumentState::Scanning, $document->fresh()->state);
        $this->assertSame(1, DocumentScan::query()->count());
        $this->assertSame(ScanVerdict::Clean, DocumentScan::query()->sole()->verdict);
    }

    private function scanDocument(): ScanDocument
    {
        return new ScanDocument(
            new LocalFilesystemObjectStorage($this->root),
            $this->paths,
            new MockScanner,
        );
    }

    /**
     * Reach ACCEPTED through the model's own transition/promote API — no
     * storage copy is involved at model level.
     */
    private function acceptedDocument(): Document
    {
        $document = $this->documentWithBytes($this->minimalPdf());
        $document->transitionTo(DocumentState::Scanning);
        $document->promote();

        return $document;
    }

    private function documentWithBytes(string $bytes): Document
    {
        $storage = new LocalFilesystemObjectStorage($this->root);
        $storageKey = 'document-'.Str::random(8);
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
