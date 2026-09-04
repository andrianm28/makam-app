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
use App\Platform\Outbox\Outbox;
use App\Platform\Outbox\OutboxClassification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
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

    /**
     * C1 regression test: proves `demo-data:seed` never claims/dispatches a
     * REAL, pre-existing `outbox_events` row — the exact live-host defect
     * this test exists to catch. Everything else in this suite runs under
     * `RefreshDatabase` against an otherwise-empty database, so this class
     * of bug (an unscoped claim query sweeping up a row it never wrote) was
     * invisible until a row like this one existed BEFORE the command ran.
     *
     * Deliberately not `TaggedAsDemoData`-tagged anywhere in its ancestry —
     * this row stands in for a real customer event that landed in
     * `outbox_events` before this run started (or arrived while it was
     * running), which the old, unscoped `OutboxPublisher::publishBatch()`
     * drain would have claimed and permanently stamped `dispatched_at` on,
     * even though the suppression guard correctly no-oped the actual
     * notification job — see `DemoDataSeedCommand`'s own top-of-file doc
     * block ("Draining the outbox NEVER calls
     * OutboxPublisher::publishBatch()").
     */
    public function test_seeding_never_touches_a_real_pre_existing_outbox_event(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped(
                'OutboxPublisher requires real Postgres row locking (SELECT ... FOR UPDATE SKIP LOCKED).'
            );
        }

        $realEvent = Outbox::record(
            eventName: 'order.status_changed.v1',
            eventVersion: 1,
            aggregateType: 'order',
            aggregateId: (string) Str::uuid(),
            data: ['order_id' => (string) Str::uuid(), 'note' => 'real customer event, never seeded'],
            classification: OutboxClassification::Internal,
        );

        $this->artisan('demo-data:seed')->assertSuccessful();

        $fresh = $realEvent->fresh();
        $this->assertNull($fresh->dispatched_at, 'a real, pre-existing outbox event must never be marked dispatched by demo-data:seed');
        $this->assertNull($fresh->locked_at, 'a real, pre-existing outbox event must never even be claimed by demo-data:seed');
    }

    /**
     * Fix-round regression test: `drainThisRunsOutboxEvents()`'s
     * correlation map originally only covered aggregate types that back a
     * `demo_batch_id`-tagged table. Five real aggregate types this run's
     * own generators produce back UNTAGGED tables instead (`quote` — via
     * `IssueOrderQuote`/`RecordBuyerApproval` on 3-5 of the 5 seeded
     * booking orders; `funeral_case` — via `SubmitBookingDraft`,
     * unconditionally, on every order; `subscription_cycle`, `work_order`,
     * `service_complaint` — via `CareSubscriptionExampleData`'s real
     * Action chain), so their outbox rows were silently left
     * `dispatched_at IS NULL` when this command exited — the real,
     * separately-scheduled `outbox:publish` job would pick them up about a
     * minute later, OUTSIDE the suppression window, which is exactly the
     * guarantee this command's own doc block and `DemoDataSuppression`'s
     * doc block both claim to provide. Confirmed empirically (not just by
     * reading source) by seeding once and inspecting `outbox_events`
     * grouped by `aggregate_type` before writing this test.
     */
    public function test_seeding_drains_every_untagged_child_aggregate_types_outbox_events_too(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped(
                'OutboxPublisher requires real Postgres row locking (SELECT ... FOR UPDATE SKIP LOCKED).'
            );
        }

        $this->artisan('demo-data:seed')->assertSuccessful();

        $untaggedChildTypes = ['quote', 'funeral_case', 'subscription_cycle', 'work_order', 'service_complaint'];

        $stillPending = DB::table('outbox_events')
            ->whereIn('aggregate_type', $untaggedChildTypes)
            ->whereNull('dispatched_at')
            ->pluck('aggregate_type');

        $this->assertCount(
            0,
            $stillPending,
            'every outbox event produced by an untagged-child aggregate type must be drained by demo-data:seed, not left for the real scheduler: still pending '.$stillPending->implode(', ')
        );

        // Sanity check: this run really does produce events for these
        // types (so the assertion above is proving something, not
        // vacuously passing because none exist).
        $totalForThoseTypes = DB::table('outbox_events')->whereIn('aggregate_type', $untaggedChildTypes)->count();
        $this->assertGreaterThan(0, $totalForThoseTypes);
    }
}
