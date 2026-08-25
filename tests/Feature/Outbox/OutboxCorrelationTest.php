<?php

declare(strict_types=1);

namespace Tests\Feature\Outbox;

use App\Platform\Correlation\CorrelationContext;
use App\Platform\Correlation\CorrelationId;
use App\Platform\Outbox\Outbox;
use App\Platform\Outbox\OutboxClassification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `Outbox::record()` is the first real consumer of S3-T10's prepared
 * correlation mechanism (finding N-10, `docs/planning/sprint-plan.md`) —
 * this proves `trace_id` is actually sourced from
 * `app(CorrelationContext::class)->current()?->value`, closing one of the
 * three consumer gaps N-10 listed. The queue-job half is now exercised by
 * `OutboxBookingDraftPublicationTest` (outbox -> `PublishOutboxEventJob`
 * dispatch) and `OutboxRecoveryTest` (the job running to completion on
 * the sync queue); provider/notification propagation remains unproven,
 * since no provider/notification classes exist in this repo yet.
 */
final class OutboxCorrelationTest extends TestCase
{
    use RefreshDatabase;

    public function test_record_sources_trace_id_from_the_current_correlation_context(): void
    {
        $id = CorrelationId::fromString('trace-abc-123');
        $this->app->make(CorrelationContext::class)->set($id);

        $row = Outbox::record(
            eventName: 'fixture.correlation_test.v1',
            eventVersion: 1,
            aggregateType: 'fixture',
            aggregateId: 1,
            data: [],
            classification: OutboxClassification::Internal,
        );

        $this->assertSame('trace-abc-123', $row->trace_id);
    }

    public function test_record_tolerates_no_correlation_id_being_bound(): void
    {
        $this->assertNull($this->app->make(CorrelationContext::class)->current());

        $row = Outbox::record(
            eventName: 'fixture.correlation_test.v1',
            eventVersion: 1,
            aggregateType: 'fixture',
            aggregateId: 1,
            data: [],
            classification: OutboxClassification::Internal,
        );

        $this->assertNull($row->trace_id);
    }
}
