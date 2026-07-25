<?php

declare(strict_types=1);

namespace Tests\Feature\Outbox;

use App\Platform\Outbox\Models\OutboxEvent;
use App\Platform\Outbox\Outbox;
use App\Platform\Outbox\OutboxClassification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Fixtures\CreatesOutboxFixtureAggregateTable;
use Tests\Fixtures\OutboxFixtureAggregate;
use Tests\TestCase;

/**
 * AC1: "insert it into the outbox in the same database transaction as the
 * state mutation. A committed mutation with no outbox row is a defect."
 *
 * Mirrors `tests/Feature/Audit/AuditWrapTransactionTest.php`'s shape, but
 * uses a direct `DB::transaction()` rather than a `wrap()`-style helper —
 * `Outbox::record()` deliberately has no `wrap()` counterpart (see that
 * class's own doc block for why), so the transaction boundary is the
 * caller's own, exactly as `Outbox::record()`'s doc block instructs.
 *
 * Uses `Tests\Fixtures\OutboxFixtureAggregate`, not a real domain model —
 * `app/Domain/**` is empty scaffolding; no real business mutation exists in
 * this repo yet to prove AC1 against. Stated plainly, matching this
 * project's established honesty discipline (see this batch's report).
 */
final class OutboxTransactionTest extends TestCase
{
    use CreatesOutboxFixtureAggregateTable;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpOutboxFixtureAggregateTable();
    }

    public function test_outbox_row_does_not_exist_when_the_wrapping_transaction_rolls_back(): void
    {
        $aggregate = OutboxFixtureAggregate::create(['status' => 'draft']);

        try {
            DB::transaction(function () use ($aggregate): void {
                $aggregate->forceFill(['status' => 'confirmed'])->save();

                Outbox::record(
                    eventName: 'fixture.aggregate_confirmed.v1',
                    eventVersion: 1,
                    aggregateType: 'outbox_fixture_aggregate',
                    aggregateId: $aggregate->id,
                    data: ['status' => 'confirmed'],
                    classification: OutboxClassification::Internal,
                );

                throw new RuntimeException('simulated failure after both writes, before commit');
            });

            $this->fail('Expected RuntimeException to propagate.');
        } catch (RuntimeException $exception) {
            $this->assertSame('simulated failure after both writes, before commit', $exception->getMessage());
        }

        // The mutation's own database change must have been rolled back
        // along with the outbox row — this is AC1's actual proof: without
        // a shared transaction, OutboxFixtureAggregate::save() above would
        // already have committed on its own before Outbox::record() ever
        // ran.
        $this->assertSame('draft', $aggregate->fresh()->status);
        $this->assertSame(0, OutboxEvent::query()->count());
    }

    public function test_outbox_row_and_mutation_commit_together_on_success(): void
    {
        $aggregate = OutboxFixtureAggregate::create(['status' => 'draft']);

        DB::transaction(function () use ($aggregate): void {
            $aggregate->forceFill(['status' => 'confirmed'])->save();

            Outbox::record(
                eventName: 'fixture.aggregate_confirmed.v1',
                eventVersion: 1,
                aggregateType: 'outbox_fixture_aggregate',
                aggregateId: $aggregate->id,
                data: ['status' => 'confirmed'],
                classification: OutboxClassification::Internal,
                idempotencyKey: 'fixture-aggregate-'.$aggregate->id.'-confirmed',
            );
        });

        $this->assertSame('confirmed', $aggregate->fresh()->status);
        $this->assertSame(1, OutboxEvent::query()->count());

        $row = OutboxEvent::query()->sole();
        $this->assertSame('fixture.aggregate_confirmed.v1', $row->event_name);
        $this->assertSame('outbox_fixture_aggregate', $row->aggregate_type);
        $this->assertSame((string) $aggregate->id, $row->aggregate_id);
        $this->assertSame(['status' => 'confirmed'], $row->payload);
        $this->assertSame(OutboxClassification::Internal->value, $row->classification);
        $this->assertNull($row->dispatched_at);
        $this->assertNull($row->locked_at);
        $this->assertSame(0, $row->attempt_count);
    }

    public function test_outbox_row_is_written_even_outside_a_transaction_but_is_then_unpaired(): void
    {
        // Outbox::record() itself does not require a transaction (a lone
        // INSERT is already atomic) — documented explicitly so a caller
        // does not mistake "works standalone" for "gets AC1's pairing
        // guarantee for free."
        $aggregate = OutboxFixtureAggregate::create(['status' => 'draft']);

        Outbox::record(
            eventName: 'fixture.aggregate_confirmed.v1',
            eventVersion: 1,
            aggregateType: 'outbox_fixture_aggregate',
            aggregateId: $aggregate->id,
            data: ['status' => 'draft'],
            classification: OutboxClassification::Internal,
        );

        $this->assertSame(1, OutboxEvent::query()->count());
    }
}
