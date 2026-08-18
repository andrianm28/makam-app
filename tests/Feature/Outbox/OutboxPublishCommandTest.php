<?php

declare(strict_types=1);

namespace Tests\Feature\Outbox;

use App\Console\Commands\OutboxPublishCommand;
use App\Platform\Outbox\Jobs\PublishOutboxEventJob;
use App\Platform\Outbox\Models\OutboxEvent;
use App\Platform\Outbox\Outbox;
use App\Platform\Outbox\OutboxClassification;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * `outbox:publish` is the production caller `OutboxPublisher::publishBatch()`
 * never had — see `App\Console\Commands\OutboxPublishCommand`'s doc block for
 * why its absence meant every domain event sat undispatched forever.
 *
 * These tests exercise the command's own contract: that it drains BEYOND one
 * batch (the bug a single-call implementation would have), that it terminates
 * on an empty queue, that it respects its safety cap, and that it rejects
 * nonsense bounds. Routing correctness and claim semantics belong to
 * `OutboxQueueRoutingTest` and `OutboxPublisherClaimTest` and are not
 * re-proved here.
 *
 * Requires real Postgres: `OutboxPublisher::claim()` refuses any other driver
 * because it depends on `SELECT ... FOR UPDATE SKIP LOCKED`. CI runs with
 * `DB_CONNECTION=pgsql`.
 */
final class OutboxPublishCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped(
                'OutboxPublisher::claim() requires real Postgres row locking '.
                '(SELECT ... FOR UPDATE SKIP LOCKED). Run with DB_CONNECTION=pgsql, as CI does.'
            );
        }
    }

    private function recordEvents(int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            Outbox::record(
                eventName: 'payment.received.v1',
                eventVersion: 1,
                aggregateType: 'fixture',
                aggregateId: $i + 1,
                data: ['amount' => 1000 + $i],
                classification: OutboxClassification::Internal,
            );
        }
    }

    public function test_it_reports_success_when_no_events_are_waiting(): void
    {
        Queue::fake();

        $this->artisan('outbox:publish')
            ->expectsOutputToContain('No outbox events were waiting.')
            ->assertExitCode(0);

        Queue::assertNothingPushed();
    }

    public function test_it_dispatches_a_waiting_event_and_marks_it_dispatched(): void
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

        $this->assertNull($row->fresh()?->dispatched_at, 'Precondition: the event starts undispatched.');

        $this->artisan('outbox:publish')->assertExitCode(0);

        Queue::assertPushed(
            PublishOutboxEventJob::class,
            fn (PublishOutboxEventJob $job): bool => $job->outboxEventId === $row->getKey()
        );

        $this->assertNotNull(
            $row->fresh()?->dispatched_at,
            'The command must mark a successfully dispatched event as dispatched.'
        );
    }

    /**
     * The load-bearing test. A single `publishBatch()` call claims at most
     * `$batchSize` rows, so an implementation that called it once per
     * invocation would leave a backlog larger than one batch permanently
     * undrained — falling further behind on every scheduled tick. Three
     * batches' worth of events must all be dispatched by ONE invocation.
     */
    public function test_it_drains_a_backlog_larger_than_a_single_batch(): void
    {
        Queue::fake();

        $this->recordEvents(7);

        $this->artisan('outbox:publish', ['--batch' => 3])->assertExitCode(0);

        $this->assertSame(
            0,
            OutboxEvent::query()->whereNull('dispatched_at')->count(),
            'Every event must be dispatched, not just the first batch.'
        );

        Queue::assertPushed(PublishOutboxEventJob::class, 7);
    }

    /**
     * The safety bound that keeps one invocation from running unboundedly
     * against a large backlog and colliding with the next scheduled tick.
     */
    public function test_it_stops_at_the_max_batches_cap_and_warns(): void
    {
        Queue::fake();

        $this->recordEvents(6);

        $this->artisan('outbox:publish', ['--batch' => 2, '--max-batches' => 2])
            ->expectsOutputToContain('Stopped at the 2-pass cap')
            ->assertExitCode(0);

        $this->assertSame(
            2,
            OutboxEvent::query()->whereNull('dispatched_at')->count(),
            'Two passes of two must leave the remaining two events for the next run.'
        );
    }

    public function test_it_does_not_warn_about_the_cap_on_a_clean_drain(): void
    {
        Queue::fake();

        $this->recordEvents(2);

        $this->artisan('outbox:publish', ['--batch' => 5, '--max-batches' => 5])
            ->doesntExpectOutputToContain('Stopped at the')
            ->assertExitCode(0);
    }

    public function test_it_rejects_a_batch_size_below_one(): void
    {
        Queue::fake();

        $this->recordEvents(1);

        $this->artisan('outbox:publish', ['--batch' => 0])
            ->expectsOutputToContain('The batch size must be at least 1.')
            ->assertExitCode(1);

        Queue::assertNothingPushed();
    }

    public function test_it_rejects_a_max_batches_below_one(): void
    {
        Queue::fake();

        $this->recordEvents(1);

        $this->artisan('outbox:publish', ['--max-batches' => 0])
            ->expectsOutputToContain('The maximum number of batches must be at least 1.')
            ->assertExitCode(1);

        Queue::assertNothingPushed();
    }

    public function test_the_scheduler_registers_the_publisher_every_minute(): void
    {
        $schedule = app(Schedule::class);

        $expressions = [];

        foreach ($schedule->events() as $event) {
            if (str_contains($event->command ?? '', 'outbox:publish')) {
                $expressions[] = $event->expression;
            }
        }

        $this->assertSame(
            ['* * * * *'],
            $expressions,
            'outbox:publish must be scheduled exactly once, every minute — a backlog that '.
            'waits for a nightly run makes order confirmations useless.'
        );
    }

    public function test_the_default_max_batches_constant_is_a_real_bound(): void
    {
        $this->assertGreaterThan(
            1,
            OutboxPublishCommand::DEFAULT_MAX_BATCHES,
            'A default of 1 would silently reintroduce the single-batch bug.'
        );
    }
}
