<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\DocumentVault\Jobs;

use App\Platform\DocumentVault\Adapters\LocalFilesystemObjectStorage;
use App\Platform\DocumentVault\DocumentKind;
use App\Platform\DocumentVault\DocumentState;
use App\Platform\DocumentVault\Jobs\ReconcileDocumentStorageCleanupJob;
use App\Platform\DocumentVault\Models\Document;
use App\Platform\DocumentVault\StoragePathPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * PERF-01 (Phase 3 Batch M7a). Before this fix,
 * `ReconcileDocumentStorageCleanupJob::handle()` scanned EVERY document in a
 * non-'accepted' storage prefix and a terminal state with no bound at all —
 * `->cursor()->each(...)` over the whole matching set. Terminal states
 * (rejected/expired/deleted) never leave that predicate, so the candidate
 * set only grows for the app's whole lifetime; a growing backlog eventually
 * makes every 5-minute scheduled run scan more rows than the last, forever.
 *
 * This proves the bound is real, not just present in the SQL: with the
 * scan limit configured to 3, seeding 5 eligible orphan documents, ONE run
 * processes only 3 of them (not all 5), and persists a resumable watermark
 * so the remaining 2 are picked up by a SECOND run — never re-scanning the
 * same row twice, never leaving a full backlog unbounded within one
 * invocation.
 */
final class ReconcileDocumentStorageCleanupJobBoundedScanTest extends TestCase
{
    use RefreshDatabase;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().'/document-vault-reconcile-'.Str::random(12);
        config(['document-vault.reconcile_orphan_scan_limit' => 3]);
        Cache::flush();
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);

        parent::tearDown();
    }

    public function test_a_single_run_processes_at_most_the_configured_limit(): void
    {
        $documents = $this->fiveOrphanDocumentsWithDistinctUpdatedAt();

        $storage = new LocalFilesystemObjectStorage($this->root);
        $paths = new StoragePathPolicy;

        (new ReconcileDocumentStorageCleanupJob)->handle($storage, $paths);

        $stillOrphaned = Document::query()
            ->where('storage_prefix', '!=', 'accepted')
            ->where('state', DocumentState::Rejected->value)
            ->count();

        // All 5 rows are untouched by this assertion (reconciliation here
        // only deletes a stray ACCEPTED-prefix object, it never mutates the
        // document row), so the real proof is the watermark below: only 3
        // of the 5 ids were ever visited by run #1.
        $this->assertSame(5, $stillOrphaned);

        $watermark = Cache::get('document-vault:reconcile-storage-cleanup:watermark');

        $this->assertNotNull($watermark, 'Expected a resumable watermark to be persisted after a bounded (non-final) run.');
        $this->assertSame($documents[2]->getKey(), $watermark['id'], 'Expected the watermark to stop after exactly the 3rd document (the configured limit).');
    }

    public function test_a_second_run_resumes_from_the_watermark_instead_of_rescanning(): void
    {
        $this->fiveOrphanDocumentsWithDistinctUpdatedAt();

        $storage = new LocalFilesystemObjectStorage($this->root);
        $paths = new StoragePathPolicy;
        $job = new ReconcileDocumentStorageCleanupJob;

        $job->handle($storage, $paths);
        $firstWatermark = Cache::get('document-vault:reconcile-storage-cleanup:watermark');

        // Second run: only 2 rows remain past the watermark, which is below
        // the configured limit of 3 — the job must finish the pass and
        // reset the watermark rather than getting stuck waiting for a full
        // page that will never arrive.
        $job->handle($storage, $paths);
        $secondWatermark = Cache::get('document-vault:reconcile-storage-cleanup:watermark');

        $this->assertNotNull($firstWatermark);
        $this->assertNull($secondWatermark, 'Expected the watermark to reset once a full pass over all 5 orphan documents completes.');
    }

    /**
     * @return list<Document>
     */
    private function fiveOrphanDocumentsWithDistinctUpdatedAt(): array
    {
        $documents = [];
        $base = Carbon::now()->subMinute();

        for ($i = 0; $i < 5; $i++) {
            $document = $this->orphanDocument();
            $document->transitionTo(DocumentState::Rejected);

            // `documents.updated_at` has second-level precision, so real
            // wall-clock sleeps between iterations are not reliably distinct
            // — a query-builder update (unlike `$model->save()`) does not
            // auto-touch `updated_at`, so this is the only way to pin each
            // row to a deterministic, strictly-increasing second without
            // the test flaking on a fast CI runner.
            Document::query()->whereKey($document->getKey())->update([
                'updated_at' => $base->copy()->addSeconds($i),
            ]);

            $documents[] = $document->fresh();
        }

        return $documents;
    }

    private function orphanDocument(): Document
    {
        $storageKey = 'document-'.Str::random(8);

        return Document::createQuarantined([
            'document_kind' => DocumentKind::Ktp,
            'owner_type' => 'booking_draft',
            'owner_id' => 'draft-'.Str::random(8),
            'original_filename' => 'document.pdf',
            'storage_prefix' => 'quarantine',
            'storage_key' => $storageKey,
            'size_bytes' => 10,
            'mime_declared' => 'application/pdf',
            'scanner_required' => true,
        ]);
    }

    private function removeDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        $items = scandir($directory);

        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $directory.'/'.$item;

            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }

        rmdir($directory);
    }
}
