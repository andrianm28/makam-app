<?php

declare(strict_types=1);

namespace App\Platform\Outbox\Jobs;

use App\Platform\Correlation\CorrelationContext;
use App\Platform\Correlation\CorrelationId;
use App\Platform\Outbox\Events\OutboxEventPublished;
use App\Platform\Outbox\Models\OutboxEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Event;

/**
 * Dispatched by `App\Platform\Outbox\OutboxPublisher::dispatchOne()` onto
 * the queue `OutboxQueueRouter::routeFor()` names for the claimed row's
 * `event_name`. Builds the versioned envelope
 * (`outbox-event-contract.md`) and fires `OutboxEventPublished` with it —
 * see that event class's own doc block for exactly what "publish" does and
 * does not mean in this minimum implementation.
 *
 * Takes the outbox event's `id` (a plain string), not the model itself —
 * standard Laravel queue practice: re-fetch fresh state in `handle()`
 * rather than serializing a possibly-stale model instance onto the queue.
 */
final class PublishOutboxEventJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $outboxEventId,
    ) {}

    public function handle(): void
    {
        $row = OutboxEvent::query()->find($this->outboxEventId);

        if ($row === null) {
            // Claimed, then the row vanished before this job ran (nothing
            // in this codebase deletes outbox_events rows today, but
            // handle() must not assume that forever) — nothing to publish.
            return;
        }

        // OBS-04: this job re-fetches fresh state rather than carrying the
        // dispatching request's ambient correlation id (see the class doc
        // block on why — the row may be processed long after, and by a
        // different worker than, whatever request originally inserted it).
        // The row's OWN `trace_id`, persisted at insertion time by
        // `Outbox::record()`, is the correct id to bind — not whatever
        // happens to be ambient in THIS worker process — and it must be
        // bound BEFORE dispatching the downstream event, so anything that
        // event's listeners do (including `Audit::record()` calls that now
        // default to the ambient id per OBS-03) inherits the SAME trace.
        if ($row->trace_id !== null) {
            app(CorrelationContext::class)->set(CorrelationId::fromString($row->trace_id));
        }

        Event::dispatch(new OutboxEventPublished($this->buildEnvelope($row)));
    }

    /**
     * @return array<string, mixed>
     */
    private function buildEnvelope(OutboxEvent $row): array
    {
        return [
            'event_id' => $row->getKey(),
            'event_name' => $row->event_name,
            'event_version' => $row->event_version,
            'occurred_at' => $row->occurred_at?->toRfc3339String(),
            'trace_id' => $row->trace_id,
            'aggregate' => [
                'type' => $row->aggregate_type,
                'id' => $row->aggregate_id,
            ],
            // Known gap, documented at the point columns were decided:
            // 2026_07_26_140000_create_outbox_events_table.php's own doc
            // block. queue-and-outbox.md §5's minimum schema has no
            // actor_type/actor_id columns to source real values from, so
            // this key is present (envelope-shape conformance) but always
            // null in this minimum implementation.
            'actor' => [
                'type' => null,
                'id' => null,
            ],
            'classification' => $row->classification,
            'idempotency_key' => $row->idempotency_key,
            'data' => $row->payload,
        ];
    }
}
