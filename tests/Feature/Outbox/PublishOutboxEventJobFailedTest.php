<?php

declare(strict_types=1);

namespace Tests\Feature\Outbox;

use App\Platform\Outbox\Events\OutboxEventPublished;
use App\Platform\Outbox\Jobs\PublishOutboxEventJob;
use App\Platform\Outbox\Models\OutboxEvent;
use App\Platform\Outbox\Outbox;
use App\Platform\Outbox\OutboxClassification;
use App\Platform\Outbox\OutboxPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Tests\TestCase;

/**
 * QUE-04: `PublishOutboxEventJob::failed()` is the terminal-failure hook
 * that reclaims a row the moment retries are exhausted, rather than relying
 * solely on `OutboxPublisher::STALE_CLAIM_SECONDS`'s 5-minute reclaim
 * window. See that job's own class doc block for why the `dispatched_at`
 * stamp had to move into `handle()` for this hook to be meaningful at all.
 */
final class PublishOutboxEventJobFailedTest extends TestCase
{
    use RefreshDatabase;

    public function test_failed_clears_the_claim_and_advances_backoff_using_the_publishers_own_formula(): void
    {
        $row = $this->recordFixtureEvent();
        $row->forceFill(['locked_at' => now(), 'attempt_count' => 1])->save();

        (new PublishOutboxEventJob($row->getKey()))->failed(new RuntimeException('listener exploded'));

        $row->refresh();
        $this->assertNull($row->locked_at);
        $this->assertNull($row->dispatched_at);
        $this->assertSame(2, $row->attempt_count);
        $this->assertSame('listener exploded', $row->last_error);
        $this->assertTrue(
            $row->available_at->greaterThan(now()),
            'available_at must be pushed into the future by OutboxPublisher::backoffSeconds().'
        );
        $this->assertEqualsWithDelta(
            OutboxPublisher::backoffSeconds(2),
            now()->diffInSeconds($row->available_at, true),
            2,
            'The backoff window must use the SAME formula OutboxPublisher::dispatchOne() uses.'
        );
    }

    public function test_failed_is_a_no_op_once_the_row_has_already_published(): void
    {
        $row = $this->recordFixtureEvent();
        $row->forceFill(['dispatched_at' => now(), 'attempt_count' => 0])->save();

        (new PublishOutboxEventJob($row->getKey()))->failed(new RuntimeException('irrelevant, arrived too late'));

        $row->refresh();
        $this->assertSame(0, $row->attempt_count, 'A post-publish failure must not touch a row that already published.');
        $this->assertNull($row->last_error);
    }

    public function test_failed_is_a_no_op_when_the_row_no_longer_exists(): void
    {
        // Must not throw — see the class doc block on why handle() (and
        // therefore failed()) must not assume the row still exists.
        (new PublishOutboxEventJob('00000000-0000-0000-0000-000000000000'))
            ->failed(new RuntimeException('row already gone'));

        $this->addToAssertionCount(1);
    }

    /**
     * The end-to-end proof: a listener that genuinely throws leaves the row
     * reclaimable on the very next `outbox:publish` tick, not stuck for
     * `OutboxPublisher::STALE_CLAIM_SECONDS`. `Event::fake()` cannot be used
     * here (it would swallow the real dispatch and this job would never
     * genuinely fail), so a real listener is registered instead, and
     * removed in `tearDown()`.
     */
    public function test_a_genuinely_failing_publish_leaves_the_row_immediately_reclaimable(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped(
                'OutboxPublisher::claim() requires real Postgres row locking. Run with DB_CONNECTION=pgsql.'
            );
        }

        Event::listen(OutboxEventPublished::class, function (): void {
            throw new RuntimeException('simulated listener failure');
        });

        $row = $this->recordFixtureEvent();

        $claimed = (new OutboxPublisher)->publishBatch();

        $this->assertSame(1, $claimed);

        $row->refresh();
        $this->assertNull($row->dispatched_at, 'A genuinely failed publish must never be marked dispatched.');
        $this->assertNull($row->locked_at, 'The claim must be released so the row is immediately reclaimable.');
        $this->assertSame(1, $row->attempt_count);
    }

    private function recordFixtureEvent(): OutboxEvent
    {
        return Outbox::record(
            eventName: 'fixture.failed_hook_test.v1',
            eventVersion: 1,
            aggregateType: 'fixture',
            aggregateId: 1,
            data: ['note' => 'failed-hook-test'],
            classification: OutboxClassification::Internal,
        );
    }
}
