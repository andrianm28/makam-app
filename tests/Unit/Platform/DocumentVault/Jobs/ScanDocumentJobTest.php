<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\DocumentVault\Jobs;

use App\Platform\DocumentVault\Actions\ScanDocument;
use App\Platform\DocumentVault\Jobs\ScanDocumentJob;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The queue job carries a document id and delegates to the Task 5 scan
 * Action; storage/scanner integration is covered by the lifecycle feature
 * tests.
 */
final class ScanDocumentJobTest extends TestCase
{
    use RefreshDatabase;

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
}
