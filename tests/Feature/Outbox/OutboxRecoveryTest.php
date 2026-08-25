<?php

declare(strict_types=1);

namespace Tests\Feature\Outbox;

use App\Platform\Outbox\Events\OutboxEventPublished;
use App\Platform\Outbox\Models\OutboxEvent;
use App\Platform\Outbox\Outbox;
use App\Platform\Outbox\OutboxClassification;
use App\Platform\Outbox\OutboxPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Fixtures\CreatesOutboxFixtureAggregateTable;
use Tests\Fixtures\OutboxFixtureAggregate;
use Tests\TestCase;

/**
 * THE test the batch brief calls out by name: "commit succeeds, dispatcher
 * dies, event still publishes on recovery. That is the whole reason the
 * outbox exists." (`docs/planning/agent-execution-plan.md` §5, Batch 3.4.)
 *
 * ---------------------------------------------------------------------------
 * What is proved here, and what is simulated — read before trusting this
 * further than it goes
 * ---------------------------------------------------------------------------
 * PROVED for real, against a real Postgres connection (this suite requires
 * `DB_CONNECTION=pgsql` — see `OutboxPublisher`'s own doc block; CI sets
 * this, `phpunit.xml`'s local default of `sqlite` does not, matching the
 * top-level `CLAUDE.md`'s "composer/tests run in CI only" instruction):
 *   1. The mutation and the outbox row commit together (AC1) and the row
 *      survives with `dispatched_at`/`locked_at` both null — nothing
 *      "cleans up" or loses a committed-but-unpublished row on its own.
 *   2. A LATER, independent call to `OutboxPublisher::publishBatch()` (a
 *      fresh instance, no shared state with the writer above) finds that
 *      row, claims it, dispatches `PublishOutboxEventJob` to its routed
 *      queue, and the row transitions to `dispatched_at IS NOT NULL`.
 *   3. The dispatched job actually RAN — not just "was pushed" — proved by
 *      asserting `OutboxEventPublished` was fired with this row's envelope.
 *      This is observable here specifically because this test environment
 *      runs `QUEUE_CONNECTION=sync` (`phpunit.xml`): the `sync` driver
 *      executes a job's `handle()` inline, synchronously, within the same
 *      `dispatch()` call that enqueues it — see
 *      `Illuminate\Queue\SyncQueue::push()`. `Event::fake()` intercepts
 *      only the `Event` facade, not the queue, so the job's real code path
 *      still runs.
 *
 * SIMULATED, not real:
 *   - "The dispatcher dies" is simulated by simply never calling the
 *     publisher after the commit — there is no actual second OS process
 *     that started and then crashed. A committed-but-unclaimed row is
 *     externally indistinguishable from "a process died right after
 *     commit" and from "no publisher has run yet for entirely mundane
 *     reasons" — this test proves the row's fate is the same (safe,
 *     recoverable) in both cases, which is the property that matters, but
 *     it does not fork a real process and kill it.
 *   - Real, asynchronous, cross-process delivery over Redis/Horizon is not
 *     exercised — `QUEUE_CONNECTION=sync` means "dispatch" and "run" happen
 *     in the same process, back to back. Nothing here proves a job
 *     survives a Redis/Horizon worker crash between push and execution;
 *     that is a Laravel Queue/Horizon-level guarantee (retry_after,
 *     Horizon's own job tracking), not this outbox's.
 */
final class OutboxRecoveryTest extends TestCase
{
    use CreatesOutboxFixtureAggregateTable;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpOutboxFixtureAggregateTable();

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped(
                'OutboxPublisher::claim() requires real Postgres row locking '.
                '(SELECT ... FOR UPDATE SKIP LOCKED). Run with DB_CONNECTION=pgsql, as CI does.'
            );
        }
    }

    public function test_a_committed_event_survives_an_unclaimed_gap_and_publishes_once_the_publisher_runs(): void
    {
        $aggregate = OutboxFixtureAggregate::create(['status' => 'draft']);

        // --- "Commit succeeds": mutation + outbox insert, one transaction ---
        DB::transaction(function () use ($aggregate): void {
            $aggregate->forceFill(['status' => 'paid'])->save();

            Outbox::record(
                eventName: 'payment.received.v1',
                eventVersion: 1,
                aggregateType: 'outbox_fixture_aggregate',
                aggregateId: $aggregate->id,
                data: ['amount' => 150000, 'currency' => 'IDR'],
                classification: OutboxClassification::Internal,
                idempotencyKey: 'fixture-payment-'.$aggregate->id,
            );
        });

        $row = OutboxEvent::query()->sole();

        // --- "Dispatcher dies": the process simply ends here. No publisher
        //     call follows. The row sits committed, unclaimed. ---
        $this->assertNull($row->dispatched_at);
        $this->assertNull($row->locked_at);
        $this->assertSame('paid', $aggregate->fresh()->status);

        // --- "Recovery": a later process runs the publisher for real. ---
        Event::fake([OutboxEventPublished::class]);

        $claimed = (new OutboxPublisher)->publishBatch();

        $this->assertSame(1, $claimed);

        $row->refresh();
        $this->assertNotNull($row->dispatched_at);
        $this->assertNull($row->locked_at);
        $this->assertSame(0, $row->attempt_count);

        // The job that got dispatched actually ran and published the
        // correct envelope — not merely "was pushed."
        Event::assertDispatched(
            OutboxEventPublished::class,
            function (OutboxEventPublished $event) use ($row): bool {
                return $event->envelope['event_id'] === $row->getKey()
                    && $event->envelope['event_name'] === 'payment.received.v1'
                    && $event->envelope['idempotency_key'] === $row->idempotency_key
                    && $event->envelope['data'] === ['amount' => 150000, 'currency' => 'IDR'];
            }
        );
    }

    public function test_a_second_publisher_run_does_not_reclaim_an_already_dispatched_row(): void
    {
        $aggregate = OutboxFixtureAggregate::create(['status' => 'draft']);

        DB::transaction(function () use ($aggregate): void {
            $aggregate->forceFill(['status' => 'paid'])->save();

            Outbox::record(
                eventName: 'payment.received.v1',
                eventVersion: 1,
                aggregateType: 'outbox_fixture_aggregate',
                aggregateId: $aggregate->id,
                data: ['amount' => 1000],
                classification: OutboxClassification::Internal,
            );
        });

        Event::fake([OutboxEventPublished::class]);

        $this->assertSame(1, (new OutboxPublisher)->publishBatch());
        $this->assertSame(0, (new OutboxPublisher)->publishBatch());

        Event::assertDispatched(OutboxEventPublished::class, 1);
    }
}
