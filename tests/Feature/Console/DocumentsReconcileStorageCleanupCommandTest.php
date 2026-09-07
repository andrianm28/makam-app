<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Platform\DocumentVault\Jobs\ReconcileDocumentStorageCleanupJob;
use App\Platform\Outbox\OutboxQueueName;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * QUE-09: `ReconcileDocumentStorageCleanupJob` had no dispatcher anywhere in
 * the application before this command existed — see the command's own class
 * doc block.
 */
final class DocumentsReconcileStorageCleanupCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_dispatches_the_reconcile_job_onto_the_media_queue(): void
    {
        Queue::fake();

        $this->artisan('documents:reconcile-storage-cleanup')->assertSuccessful();

        Queue::assertPushedOn(
            OutboxQueueName::Media->value,
            ReconcileDocumentStorageCleanupJob::class,
        );
    }

    public function test_it_is_scheduled_hourly_with_an_explicit_overlap_expiry(): void
    {
        $schedule = app(Schedule::class);

        $event = collect($schedule->events())
            ->first(fn ($event): bool => str_contains($event->command ?? '', 'documents:reconcile-storage-cleanup'));

        $this->assertNotNull($event, 'documents:reconcile-storage-cleanup must be scheduled.');
        $this->assertSame('0 * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping, 'The schedule entry must guard against overlap.');
        $this->assertSame(
            30,
            $event->expiresAt,
            'QUE-07: every frequent scheduled entry must use an explicit short mutex expiry, never the 24-hour default.'
        );
    }
}
