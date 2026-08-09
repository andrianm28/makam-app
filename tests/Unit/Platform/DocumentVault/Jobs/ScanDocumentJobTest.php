<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\DocumentVault\Jobs;

use App\Platform\DocumentVault\Jobs\ScanDocumentJob;
use Illuminate\Contracts\Queue\ShouldQueue;
use Tests\TestCase;

/**
 * Task 4 scope only: the job exists, is queueable, and carries the document
 * id. The actual scan dispatch inside `handle()` is Task 5's
 * `Actions\ScanDocument` (`task-5-brief.md`) — not built yet, so this suite
 * only proves `handle()` runs cleanly as the documented no-op placeholder,
 * not that any scanning happened.
 */
final class ScanDocumentJobTest extends TestCase
{
    public function test_it_is_queueable_and_carries_the_document_id(): void
    {
        $job = new ScanDocumentJob('11111111-1111-1111-1111-111111111111');

        $this->assertInstanceOf(ShouldQueue::class, $job);
        $this->assertSame('11111111-1111-1111-1111-111111111111', $job->documentId);
    }

    public function test_handle_runs_without_error_as_a_task_5_placeholder(): void
    {
        $job = new ScanDocumentJob('11111111-1111-1111-1111-111111111111');

        $job->handle();

        $this->addToAssertionCount(1);
    }
}
