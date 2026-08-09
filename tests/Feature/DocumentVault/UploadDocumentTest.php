<?php

declare(strict_types=1);

namespace Tests\Feature\DocumentVault;

use App\Platform\DocumentVault\Actions\UploadDocument;
use App\Platform\DocumentVault\Adapters\LocalFilesystemObjectStorage;
use App\Platform\DocumentVault\DocumentKind;
use App\Platform\DocumentVault\DocumentState;
use App\Platform\DocumentVault\DocumentValidator;
use App\Platform\DocumentVault\Exceptions\DocumentValidationException;
use App\Platform\DocumentVault\Jobs\ScanDocumentJob;
use App\Platform\DocumentVault\Models\Document;
use App\Platform\DocumentVault\StoragePathPolicy;
use App\Platform\Outbox\Models\OutboxEvent;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Covers task-4-brief.md Step 4: quarantine-first (no row ever starts
 * ACCEPTED), resume returns the same row, GRAVE_IMPORT passes validation
 * through the identical path, and the outbox row carries no content key.
 * `DocumentTest` (Unit) covers the enum-cast half of the
 * "Document::create(['state' => 'accepted']) impossible" requirement.
 */
final class UploadDocumentTest extends TestCase
{
    use RefreshDatabase;

    private string $root;

    private UploadDocument $action;

    protected function setUp(): void
    {
        parent::setUp();

        // A throwaway root per test, same precedent as
        // LocalFilesystemObjectStorageTest — never the real dev
        // storage/app/private tree.
        $this->root = sys_get_temp_dir().'/document-vault-upload-test-'.Str::random(12);

        $this->action = new UploadDocument(
            new LocalFilesystemObjectStorage($this->root),
            new StoragePathPolicy,
            new DocumentValidator,
        );
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);

        parent::tearDown();
    }

    public function test_it_quarantines_a_new_upload_and_never_writes_under_accepted(): void
    {
        Queue::fake();

        $document = $this->action->upload(
            DocumentKind::Ktp,
            $this->uploadedFile($this->minimalPdf(), 'ktp.pdf', 'application/pdf'),
            'booking_draft',
            'draft-1',
            null,
            [],
        );

        $this->assertSame(DocumentState::Quarantined, $document->state);
        $this->assertSame('quarantine', $document->storage_prefix);
        $this->assertFileExists("{$this->root}/KTP/quarantine/{$document->storage_key}");
        $this->assertDirectoryDoesNotExist("{$this->root}/KTP/accepted");
        $this->assertSame(1, Document::query()->count());
        $this->assertTrue($document->scanner_required);

        Queue::assertPushedOn(
            'media',
            ScanDocumentJob::class,
            fn (ScanDocumentJob $job): bool => $job->documentId === $document->id,
        );
    }

    public function test_the_outbox_row_carries_only_a_reference_never_content(): void
    {
        Queue::fake();

        $document = $this->action->upload(
            DocumentKind::Ktp,
            $this->uploadedFile($this->minimalPdf(), 'super-secret-ktp.pdf', 'application/pdf'),
            'booking_draft',
            'draft-1',
            null,
            [],
        );

        $outboxRow = OutboxEvent::query()->sole();

        $this->assertSame('document.uploaded.v1', $outboxRow->event_name);
        $this->assertSame(1, $outboxRow->event_version);
        $this->assertSame('document', $outboxRow->aggregate_type);
        $this->assertSame($document->id, $outboxRow->aggregate_id);
        $this->assertSame(['kind', 'state'], array_keys($outboxRow->payload));
        $this->assertSame('KTP', $outboxRow->payload['kind']);
        $this->assertSame('quarantined', $outboxRow->payload['state']);
        $this->assertSame('CONFIDENTIAL', $outboxRow->classification);
        $this->assertSame("upload:{$document->id}", $outboxRow->idempotency_key);
    }

    public function test_resume_with_a_matching_client_upload_id_returns_the_same_row_and_does_not_duplicate_side_effects(): void
    {
        Queue::fake();

        $first = $this->action->upload(
            DocumentKind::Ktp,
            $this->uploadedFile($this->minimalPdf(), 'ktp.pdf', 'application/pdf'),
            'booking_draft',
            'draft-1',
            'resume-token-1',
            [],
        );

        $second = $this->action->upload(
            DocumentKind::Ktp,
            $this->uploadedFile($this->minimalPdf(), 'ktp-retry.pdf', 'application/pdf'),
            'booking_draft',
            'draft-1',
            'resume-token-1',
            [],
        );

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Document::query()->count());
        $this->assertSame(1, OutboxEvent::query()->count());
        Queue::assertPushed(ScanDocumentJob::class, 1);

        // "update storage, keep state" — the row's descriptive columns
        // reflect the retried attempt, but its state never moved.
        $this->assertSame('ktp-retry.pdf', $second->original_filename);
        $this->assertSame(DocumentState::Quarantined, $second->state);
        $this->assertFileExists("{$this->root}/KTP/quarantine/{$second->storage_key}");
    }

    public function test_scan_dispatch_waits_until_the_outer_transaction_commits(): void
    {
        Queue::fake();

        $assertedNothingWasPushedInsideTransaction = false;

        DB::transaction(function () use (&$assertedNothingWasPushedInsideTransaction): void {
            $this->action->upload(
                DocumentKind::Ktp,
                $this->uploadedFile($this->minimalPdf(), 'ktp.pdf', 'application/pdf'),
                'booking_draft',
                'draft-commit-timing',
                null,
                [],
            );

            Queue::assertNothingPushed();
            $assertedNothingWasPushedInsideTransaction = true;
        });

        $this->assertTrue($assertedNothingWasPushedInsideTransaction);
        Queue::assertPushed(ScanDocumentJob::class, 1);
    }

    public function test_direct_create_cannot_insert_an_accepted_document_state(): void
    {
        $this->expectException(QueryException::class);

        Document::create([
            'document_kind' => DocumentKind::Ktp,
            'state' => DocumentState::Accepted,
            'owner_type' => 'booking_draft',
            'owner_id' => 'draft-direct-accepted',
            'original_filename' => 'ktp.pdf',
            'storage_prefix' => 'quarantine',
            'storage_key' => 'direct-accepted-key',
            'size_bytes' => 100,
            'mime_declared' => 'application/pdf',
            'scanner_required' => true,
        ]);
    }

    public function test_resume_does_not_replace_bytes_when_document_is_scanning(): void
    {
        Queue::fake();

        $document = $this->action->upload(
            DocumentKind::Ktp,
            $this->uploadedFile($this->minimalPdf(), 'ktp.pdf', 'application/pdf'),
            'booking_draft',
            'draft-scanning',
            'resume-scanning',
            [],
        );
        $path = "{$this->root}/KTP/quarantine/{$document->storage_key}";
        $originalBytes = file_get_contents($path);
        $document->transitionTo(DocumentState::Scanning);

        $this->expectException(InvalidArgumentException::class);

        try {
            $this->action->upload(
                DocumentKind::Ktp,
                $this->uploadedFile($this->minimalPdf().'retry', 'ktp-retry.pdf', 'application/pdf'),
                'booking_draft',
                'draft-scanning',
                'resume-scanning',
                [],
            );
        } finally {
            $this->assertSame($originalBytes, file_get_contents($path));
            $this->assertSame(DocumentState::Scanning, $document->fresh()->state);
        }
    }

    public function test_resume_does_not_replace_bytes_when_document_is_accepted(): void
    {
        Queue::fake();

        $document = $this->action->upload(
            DocumentKind::Ktp,
            $this->uploadedFile($this->minimalPdf(), 'ktp.pdf', 'application/pdf'),
            'booking_draft',
            'draft-accepted',
            'resume-accepted',
            [],
        );
        $path = "{$this->root}/KTP/quarantine/{$document->storage_key}";
        $originalBytes = file_get_contents($path);
        $document->transitionTo(DocumentState::Scanning);
        $document->promote();

        $this->expectException(InvalidArgumentException::class);

        try {
            $this->action->upload(
                DocumentKind::Ktp,
                $this->uploadedFile($this->minimalPdf().'retry', 'ktp-retry.pdf', 'application/pdf'),
                'booking_draft',
                'draft-accepted',
                'resume-accepted',
                [],
            );
        } finally {
            $this->assertSame($originalBytes, file_get_contents($path));
            $this->assertSame(DocumentState::Accepted, $document->fresh()->state);
        }
    }

    public function test_resume_rejects_a_token_owned_by_another_record(): void
    {
        Queue::fake();

        $document = $this->action->upload(
            DocumentKind::Ktp,
            $this->uploadedFile($this->minimalPdf(), 'ktp.pdf', 'application/pdf'),
            'booking_draft',
            'draft-owner-a',
            'resume-owner-a',
            [],
        );
        $path = "{$this->root}/KTP/quarantine/{$document->storage_key}";
        $originalBytes = file_get_contents($path);

        $this->expectException(InvalidArgumentException::class);

        try {
            $this->action->upload(
                DocumentKind::Ktp,
                $this->uploadedFile($this->minimalPdf(), 'ktp-owner-b.pdf', 'application/pdf'),
                'booking_draft',
                'draft-owner-b',
                'resume-owner-a',
                [],
            );
        } finally {
            $this->assertSame($originalBytes, file_get_contents($path));
            $this->assertSame($document->id, Document::query()->sole()->id);
        }
    }

    public function test_grave_import_kind_routes_through_the_identical_quarantine_path(): void
    {
        Queue::fake();

        $csv = "plot,name\n1,John Doe\n";

        $document = $this->action->upload(
            DocumentKind::GraveImport,
            $this->uploadedFile($csv, 'import.csv', 'text/csv'),
            'grave_import_batch',
            'batch-1',
            null,
            [],
        );

        $this->assertSame(DocumentState::Quarantined, $document->state);
        $this->assertTrue($document->scanner_required);
        $this->assertFileExists("{$this->root}/GRAVE_IMPORT/quarantine/{$document->storage_key}");

        Queue::assertPushedOn('media', ScanDocumentJob::class);
    }

    public function test_a_rejected_upload_creates_no_row_no_outbox_event_and_dispatches_no_scan(): void
    {
        Queue::fake();

        try {
            $this->action->upload(
                DocumentKind::Ktp,
                $this->uploadedFile('not a real pdf', 'ktp.exe', 'application/octet-stream'),
                'booking_draft',
                'draft-2',
                null,
                [],
            );
            $this->fail('Expected DocumentValidationException.');
        } catch (DocumentValidationException) {
            // expected — extension not allowed for KTP.
        }

        $this->assertSame(0, Document::query()->count());
        $this->assertSame(0, OutboxEvent::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_a_rejected_upload_does_not_disturb_a_sibling_document(): void
    {
        Queue::fake();

        $sibling = $this->action->upload(
            DocumentKind::Ktp,
            $this->uploadedFile($this->minimalPdf(), 'ktp.pdf', 'application/pdf'),
            'booking_draft',
            'draft-3',
            null,
            [],
        );

        try {
            $this->action->upload(
                DocumentKind::Ktp,
                $this->uploadedFile('not a real pdf', 'ktp.exe', 'application/octet-stream'),
                'booking_draft',
                'draft-3',
                null,
                [],
            );
            $this->fail('Expected DocumentValidationException.');
        } catch (DocumentValidationException) {
            // expected
        }

        $sibling->refresh();
        $this->assertSame(DocumentState::Quarantined, $sibling->state);
        $this->assertSame(1, Document::query()->count());
    }

    public function test_a_psr7_stream_input_uses_meta_for_filename_and_declared_mime(): void
    {
        Queue::fake();

        $csv = "plot,name\n1,John Doe\n";
        $stream = Utils::streamFor($csv);

        $document = $this->action->upload(
            DocumentKind::GraveImport,
            $stream,
            'grave_import_batch',
            'batch-2',
            null,
            ['original_filename' => 'import.csv', 'mime_declared' => 'text/csv'],
        );

        $this->assertSame('import.csv', $document->original_filename);
        $this->assertSame('text/csv', $document->mime_declared);
        $this->assertFileExists("{$this->root}/GRAVE_IMPORT/quarantine/{$document->storage_key}");

        $stream->rewind();
        $this->assertSame($csv, $stream->getContents());
    }

    public function test_a_psr7_stream_input_without_required_meta_throws(): void
    {
        $stream = Utils::streamFor("plot,name\n1,John Doe\n");

        $this->expectException(InvalidArgumentException::class);

        $this->action->upload(
            DocumentKind::GraveImport,
            $stream,
            'grave_import_batch',
            'batch-3',
            null,
            [],
        );
    }

    private function uploadedFile(string $content, string $filename, string $mimeType): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'doc-vault-upload-');
        file_put_contents($path, $content);

        return new UploadedFile($path, $filename, $mimeType, null, true);
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

        $items = scandir($directory) ?: [];

        foreach ($items as $item) {
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
