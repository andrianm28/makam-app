<?php

declare(strict_types=1);

namespace Tests\Feature\Outbox;

use App\Platform\Outbox\Events\OutboxEventPublished;
use App\Platform\Outbox\Models\OutboxEvent;
use App\Platform\Outbox\Outbox;
use App\Platform\Outbox\OutboxClassification;
use App\Platform\Outbox\OutboxPublisher;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * AC5: "make a publisher's claim on an event atomic. THE SYSTEM SHALL NOT
 * let two concurrent publishers publish the same event twice."
 *
 * ---------------------------------------------------------------------------
 * A structural limitation of this test SUITE, not of `OutboxPublisher` —
 * read before assuming genuine concurrency was exercised
 * ---------------------------------------------------------------------------
 * This project's `RefreshDatabase` trait wraps EACH test method in an outer
 * database transaction that is rolled back at tear-down, on the test
 * process's one default connection. A genuinely separate second database
 * connection/session — the only way to prove `SELECT ... FOR UPDATE SKIP
 * LOCKED` actually skips a row a DIFFERENT session is holding — cannot see
 * that outer transaction's uncommitted rows at all under Postgres MVCC:
 * whatever fixture data this test creates is invisible to a second raw
 * connection until (if ever) the outer transaction commits, which
 * `RefreshDatabase` deliberately never does. A test that opened a second
 * `DB::connection()` here would not be proving concurrent-session lock
 * contention; it would just be proving that connection sees an empty
 * table, which proves nothing about AC5.
 *
 * Building genuine cross-session proof would need a non-transactional test
 * harness (e.g. `DatabaseTransactions` traded for real commit + manual
 * cleanup, or two separate PHPUnit processes) — out of scope for this
 * "minimum outbox" batch. What IS provable, and is proved below, inside
 * this suite's real single-session Postgres connection:
 *   - the claim query's WHERE/lock semantics are correct: it claims
 *     eligible rows, sets `locked_at`, and excludes rows that are already
 *     dispatched or freshly locked;
 *   - a stale claim (older than `OutboxPublisher::STALE_CLAIM_SECONDS`) IS
 *     reclaimed, matching design.md's documented stale-claim behaviour;
 *   - two SEQUENTIAL publisher runs never double-claim the same row (a
 *     weaker, sequential-only proof — real overlapping-session SKIP LOCKED
 *     behaviour is NOT exercised here, per the limitation above).
 */
final class OutboxPublisherClaimTest extends TestCase
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

    public function test_claim_excludes_a_row_locked_within_the_stale_claim_window(): void
    {
        $row = $this->recordFixtureEvent();

        $row->forceFill(['locked_at' => CarbonImmutable::now()])->save();

        Event::fake([OutboxEventPublished::class]);

        $claimed = (new OutboxPublisher)->publishBatch();

        $this->assertSame(0, $claimed);
        $this->assertNull($row->fresh()->dispatched_at);
        Event::assertNotDispatched(OutboxEventPublished::class);
    }

    public function test_claim_reclaims_a_stale_lock_older_than_the_reclaim_window(): void
    {
        $row = $this->recordFixtureEvent();

        $row->forceFill([
            'locked_at' => CarbonImmutable::now()->subSeconds(OutboxPublisher::STALE_CLAIM_SECONDS + 60),
        ])->save();

        Event::fake([OutboxEventPublished::class]);

        $claimed = (new OutboxPublisher)->publishBatch();

        $this->assertSame(1, $claimed);
        $this->assertNotNull($row->fresh()->dispatched_at);
    }

    public function test_claim_excludes_an_already_dispatched_row(): void
    {
        $row = $this->recordFixtureEvent();
        $row->forceFill(['dispatched_at' => CarbonImmutable::now()])->save();

        Event::fake([OutboxEventPublished::class]);

        $claimed = (new OutboxPublisher)->publishBatch();

        $this->assertSame(0, $claimed);
        Event::assertNotDispatched(OutboxEventPublished::class);
    }

    public function test_claim_excludes_a_row_not_yet_available_for_backoff_retry(): void
    {
        $row = $this->recordFixtureEvent();

        $row->forceFill([
            'attempt_count' => 1,
            'available_at' => CarbonImmutable::now()->addMinutes(5),
        ])->save();

        Event::fake([OutboxEventPublished::class]);

        $claimed = (new OutboxPublisher)->publishBatch();

        $this->assertSame(0, $claimed);
    }

    public function test_two_sequential_publisher_runs_never_double_claim_the_same_row(): void
    {
        // Weaker, sequential-only proof — see this class's own doc block
        // for why genuine overlapping-session concurrency is not provable
        // in this test suite.
        $row = $this->recordFixtureEvent();

        Event::fake([OutboxEventPublished::class]);

        $first = (new OutboxPublisher)->publishBatch();
        $second = (new OutboxPublisher)->publishBatch();

        $this->assertSame(1, $first);
        $this->assertSame(0, $second);
        Event::assertDispatched(OutboxEventPublished::class, 1);
    }

    public function test_claim_respects_the_batch_size_limit(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->recordFixtureEvent();
        }

        Event::fake([OutboxEventPublished::class]);

        $claimed = (new OutboxPublisher)->publishBatch(batchSize: 2);

        $this->assertSame(2, $claimed);
        $this->assertSame(1, OutboxEvent::query()->whereNull('dispatched_at')->count());
    }

    private function recordFixtureEvent(): OutboxEvent
    {
        return Outbox::record(
            eventName: 'fixture.claim_test.v1',
            eventVersion: 1,
            aggregateType: 'fixture',
            aggregateId: 1,
            data: ['note' => 'claim-test'],
            classification: OutboxClassification::Internal,
        );
    }
}
