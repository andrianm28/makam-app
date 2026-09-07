<?php

declare(strict_types=1);

namespace Tests\Feature\Correlation;

use App\Platform\Correlation\CorrelationContext;
use App\Platform\Notification\Actions\DispatchNotification;
use App\Platform\Outbox\Events\OutboxEventPublished;
use App\Platform\Outbox\Jobs\PublishOutboxEventJob;
use App\Platform\Outbox\Models\OutboxEvent;
use App\Platform\Outbox\OutboxClassification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * OBS-04: only 1 of 9 queued jobs adopted `CarriesCorrelationId` before this
 * fix, so a trace/correlation id was dropped at every other queue hop. Two
 * of the fixed jobs — `PublishOutboxEventJob` and the notification consumer
 * driven by `DispatchNotification::consumeOutboxEvent()` — bind from the
 * ROW's/ENVELOPE's own `trace_id`, not from ambient capture at construction
 * time (see both classes' own doc blocks for why), so they need a dedicated
 * proof distinct from a plain `CarriesCorrelationId` capture/restore round
 * trip.
 */
final class QueueJobCorrelationPropagationTest extends TestCase
{
    use RefreshDatabase;

    public function test_publish_outbox_event_job_binds_the_rows_trace_id_before_dispatching(): void
    {
        $row = OutboxEvent::create([
            'event_name' => 'fixture.correlation_propagation.v1',
            'event_version' => 1,
            'aggregate_type' => 'fixture',
            'aggregate_id' => '1',
            'payload' => [],
            'classification' => OutboxClassification::Internal->value,
            'occurred_at' => now(),
            'available_at' => now(),
            'attempt_count' => 0,
            'trace_id' => 'trace-obs04-publish',
            'idempotency_key' => null,
        ]);

        $this->assertNull($this->app->make(CorrelationContext::class)->current());

        $observed = null;
        Event::listen(OutboxEventPublished::class, function () use (&$observed): void {
            $observed = $this->app->make(CorrelationContext::class)->current()?->value;
        });

        (new PublishOutboxEventJob($row->getKey()))->handle();

        $this->assertSame('trace-obs04-publish', $observed);
        $this->assertSame('trace-obs04-publish', $this->app->make(CorrelationContext::class)->current()?->value);
    }

    public function test_publish_outbox_event_job_leaves_context_unbound_when_the_row_has_no_trace_id(): void
    {
        $row = OutboxEvent::create([
            'event_name' => 'fixture.correlation_propagation.v1',
            'event_version' => 1,
            'aggregate_type' => 'fixture',
            'aggregate_id' => '2',
            'payload' => [],
            'classification' => OutboxClassification::Internal->value,
            'occurred_at' => now(),
            'available_at' => now(),
            'attempt_count' => 0,
            'trace_id' => null,
            'idempotency_key' => null,
        ]);

        (new PublishOutboxEventJob($row->getKey()))->handle();

        $this->assertNull($this->app->make(CorrelationContext::class)->current());
    }

    /**
     * `DispatchNotification::consumeOutboxEvent()` binds the outbox row's
     * `trace_id` before doing any further work — proved here via a template
     * lookup that returns nothing (a `TEMPLATE_UNAVAILABLE`-free no-op is
     * NOT what we need; instead assert the context is bound even when no
     * template matches, since the bind happens before that check).
     */
    public function test_consume_outbox_event_binds_the_envelopes_trace_id_before_any_further_work(): void
    {
        $row = OutboxEvent::create([
            'event_name' => 'fixture.no_matching_template.v1',
            'event_version' => 1,
            'aggregate_type' => 'fixture',
            'aggregate_id' => '3',
            'payload' => [],
            'classification' => OutboxClassification::Internal->value,
            'occurred_at' => now(),
            'available_at' => now(),
            'attempt_count' => 0,
            'trace_id' => 'trace-obs04-notification',
            'idempotency_key' => null,
        ]);

        $this->assertSame(
            0,
            (int) DB::table('notification_templates')->where('outbox_event_name', $row->event_name)->count(),
            'This fixture event name must match no template, so consumeOutboxEvent() returns early after the bind.',
        );

        $this->app->make(DispatchNotification::class)->consumeOutboxEvent($row->getKey());

        $this->assertSame(
            'trace-obs04-notification',
            $this->app->make(CorrelationContext::class)->current()?->value,
        );
    }
}
