<?php

declare(strict_types=1);

namespace Tests\Feature\Outbox;

use App\Platform\Outbox\Jobs\PublishOutboxEventJob;
use App\Platform\Outbox\Outbox;
use App\Platform\Outbox\OutboxClassification;
use App\Platform\Outbox\OutboxPublisher;
use App\Platform\Outbox\OutboxQueueName;
use App\Platform\Outbox\OutboxQueueRouter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * AC8: "route queues per the names and priorities in `queue-and-outbox.md`
 * ... SHALL NOT let `imports`, `media`, or `reports` starve `critical` or
 * `urgent`."
 *
 * What this proves and does not: routing CORRECTNESS — a given event name
 * lands on the right named queue. It does NOT prove starvation prevention
 * under load — that depends on separate Horizon worker pools per queue
 * (`queue-and-outbox.md` §3's supervisor topology), which
 * `agent-execution-plan.md` explicitly defers to Sprint 6 ("Horizon
 * supervisors ... are Sprint 6"). Routing an `imports` event onto the
 * `imports` queue and a `critical` event onto `critical` is the necessary
 * precondition for that future isolation to work at all; it is not itself
 * the isolation.
 */
final class OutboxQueueRoutingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped(
                'OutboxPublisher::claim() requires real Postgres row locking. Run with DB_CONNECTION=pgsql.'
            );
        }
    }

    public function test_router_maps_the_known_critical_event_to_the_critical_queue(): void
    {
        $this->assertSame(
            OutboxQueueName::Critical,
            OutboxQueueRouter::routeFor('payment.received.v1')
        );
    }

    public function test_router_maps_the_known_imports_event_to_the_imports_queue(): void
    {
        $this->assertSame(
            OutboxQueueName::Imports,
            OutboxQueueRouter::routeFor('grave.import_completed.v1')
        );
    }

    public function test_router_falls_back_to_the_default_queue_for_an_unmapped_event_name(): void
    {
        $this->assertSame(
            OutboxQueueName::Default,
            OutboxQueueRouter::routeFor('some.unmapped_event.v1')
        );
    }

    public function test_publisher_dispatches_a_critical_fixture_event_onto_the_critical_queue(): void
    {
        Queue::fake();

        $row = Outbox::record(
            eventName: 'payment.received.v1',
            eventVersion: 1,
            aggregateType: 'fixture',
            aggregateId: 1,
            data: ['amount' => 5000],
            classification: OutboxClassification::Internal,
        );

        (new OutboxPublisher)->publishBatch();

        Queue::assertPushedOn(
            OutboxQueueName::Critical->value,
            PublishOutboxEventJob::class,
            fn (PublishOutboxEventJob $job): bool => $job->outboxEventId === $row->getKey()
        );
    }

    public function test_publisher_dispatches_an_imports_fixture_event_onto_the_imports_queue(): void
    {
        Queue::fake();

        $row = Outbox::record(
            eventName: 'grave.import_completed.v1',
            eventVersion: 1,
            aggregateType: 'fixture',
            aggregateId: 2,
            data: ['imported' => 9500, 'skipped' => 500],
            classification: OutboxClassification::Internal,
        );

        (new OutboxPublisher)->publishBatch();

        Queue::assertPushedOn(
            OutboxQueueName::Imports->value,
            PublishOutboxEventJob::class,
            fn (PublishOutboxEventJob $job): bool => $job->outboxEventId === $row->getKey()
        );
    }

    public function test_publisher_dispatches_an_unmapped_fixture_event_onto_the_default_queue(): void
    {
        Queue::fake();

        $row = Outbox::record(
            eventName: 'fixture.unmapped_event.v1',
            eventVersion: 1,
            aggregateType: 'fixture',
            aggregateId: 3,
            data: [],
            classification: OutboxClassification::Internal,
        );

        (new OutboxPublisher)->publishBatch();

        Queue::assertPushedOn(
            OutboxQueueName::Default->value,
            PublishOutboxEventJob::class,
            fn (PublishOutboxEventJob $job): bool => $job->outboxEventId === $row->getKey()
        );
    }
}
