<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Domain\CareSubscription\Models\Subscription;
use App\Domain\Marketplace\Models\Vendor;
use App\Domain\OrderWorkflow\Models\Order;
use App\Domain\Renewal\Models\Renewal;
use App\Domain\Visitation\Models\VisitationBooking;
use App\Models\DemoDataBatch;
use App\Platform\Notification\Jobs\ConsumeOutboxNotificationJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class DemoDataSeedCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_seeds_every_domain_and_records_a_batch(): void
    {
        $this->artisan('demo-data:seed')->assertSuccessful();

        $this->assertDatabaseCount('demo_data_batches', 1);

        $batchId = DemoDataBatch::query()->value('batch_id');

        $this->assertGreaterThan(0, Order::query()->where('demo_batch_id', $batchId)->count());
        $this->assertGreaterThan(0, Renewal::query()->where('demo_batch_id', $batchId)->count());
        $this->assertGreaterThan(0, Vendor::query()->where('demo_batch_id', $batchId)->count());
        $this->assertGreaterThan(0, Subscription::query()->where('demo_batch_id', $batchId)->count());
        $this->assertGreaterThan(0, VisitationBooking::query()->where('demo_batch_id', $batchId)->count());
    }

    /**
     * A bare `Queue::fake()` also fakes `PublishOutboxEventJob` — the job
     * that actually fires `OutboxEventPublished` in the first place (see
     * Task 3's own real finding, and `handle()`'s doc comment on why the
     * command forces `queue.default` to `sync` and drains the outbox
     * itself). Faking it would mean nothing ever reaches the two
     * suppression-guarded listeners at all, and this test would pass for
     * the wrong reason — proving nothing ran, not that suppression
     * worked. Scope the fake to the one job that must never be queued.
     */
    public function test_seeding_never_queues_a_real_notification_job(): void
    {
        Queue::fake([ConsumeOutboxNotificationJob::class]);

        $this->artisan('demo-data:seed')->assertSuccessful();

        Queue::assertNotPushed(ConsumeOutboxNotificationJob::class);
    }
}
