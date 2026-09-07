<?php

declare(strict_types=1);

namespace Tests\Feature\Outbox;

use App\Console\Commands\OutboxReplayCommand;
use App\Platform\Audit\Models\AuditEvent;
use App\Platform\Outbox\Models\OutboxEvent;
use App\Platform\Outbox\Outbox;
use App\Platform\Outbox\OutboxClassification;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * QUE-04's bounded, privileged, reason-required manual replay command —
 * `docs/architecture/queue-and-outbox.md` §8: "Manual replay requires
 * privileged permission, reason, and audit."
 */
final class OutboxReplayCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_replay_clears_the_claim_and_makes_the_row_immediately_available(): void
    {
        $row = $this->recordFixtureEvent();
        $row->forceFill([
            'locked_at' => CarbonImmutable::now(),
            'attempt_count' => 2,
            'available_at' => CarbonImmutable::now()->addMinutes(5),
        ])->save();

        $this->artisan('outbox:replay', [
            'ids' => [$row->getKey()],
            '--reason' => 'Stuck after a crash-looping worker, ticket OPS-9001',
        ])->assertSuccessful();

        $row->refresh();
        $this->assertNull($row->locked_at);
        $this->assertTrue($row->available_at->lessThanOrEqualTo(CarbonImmutable::now()));
        $this->assertNull($row->dispatched_at);
    }

    public function test_replay_writes_an_audited_outbox_event_replay_row(): void
    {
        $row = $this->recordFixtureEvent();

        $this->artisan('outbox:replay', [
            'ids' => [$row->getKey()],
            '--reason' => 'Manual recovery per incident OPS-9001',
        ])->assertSuccessful();

        $this->assertDatabaseHas('audit_events', [
            'action' => 'OUTBOX_EVENT_REPLAY',
            'subject_type' => 'outbox_event',
            'subject_id' => $row->getKey(),
            'outcome' => 'allowed',
        ]);

        $auditEvent = AuditEvent::query()->where('action', 'OUTBOX_EVENT_REPLAY')->sole();
        $this->assertSame('Manual recovery per incident OPS-9001', $auditEvent->reason);
    }

    public function test_replay_fails_without_a_reason(): void
    {
        $row = $this->recordFixtureEvent();

        $this->artisan('outbox:replay', ['ids' => [$row->getKey()]])->assertFailed();

        $this->assertDatabaseCount('audit_events', 0);
        $this->assertNotNull($row->fresh());
    }

    public function test_replay_fails_with_a_blank_reason(): void
    {
        $row = $this->recordFixtureEvent();

        $this->artisan('outbox:replay', [
            'ids' => [$row->getKey()],
            '--reason' => '   ',
        ])->assertFailed();

        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_replay_skips_an_already_published_row_and_writes_no_audit_event(): void
    {
        $row = $this->recordFixtureEvent();
        $row->forceFill(['dispatched_at' => CarbonImmutable::now()])->save();

        $this->artisan('outbox:replay', [
            'ids' => [$row->getKey()],
            '--reason' => 'Attempted replay of an already-published row',
        ])->assertFailed(); // 0 replayed, 1 skipped -> FAILURE per the command's own contract

        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_replay_skips_an_unknown_id_without_aborting_eligible_ones(): void
    {
        $row = $this->recordFixtureEvent();

        $this->artisan('outbox:replay', [
            'ids' => [$row->getKey(), 'does-not-exist'],
            '--reason' => 'Batch replay including one bad id',
        ])->assertSuccessful();

        $this->assertDatabaseCount('audit_events', 1);
    }

    public function test_replay_refuses_more_ids_than_the_bounded_cap(): void
    {
        $ids = [];

        for ($i = 0; $i < OutboxReplayCommand::MAX_IDS_PER_INVOCATION + 1; $i++) {
            $ids[] = $this->recordFixtureEvent((string) $i)->getKey();
        }

        $this->artisan('outbox:replay', [
            'ids' => $ids,
            '--reason' => 'Attempted mass replay over the bounded cap',
        ])->assertFailed();

        $this->assertDatabaseCount('audit_events', 0);
    }

    private function recordFixtureEvent(string $suffix = '1'): OutboxEvent
    {
        return Outbox::record(
            eventName: 'fixture.replay_test.v1',
            eventVersion: 1,
            aggregateType: 'fixture',
            aggregateId: $suffix,
            data: ['note' => 'replay-test-'.$suffix],
            classification: OutboxClassification::Internal,
            idempotencyKey: 'fixture-replay-'.$suffix.'-'.uniqid('', true),
        );
    }
}
