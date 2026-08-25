<?php

declare(strict_types=1);

namespace App\Platform\Notification\Jobs;

use App\Platform\Notification\Actions\DispatchNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * The outbox-fed notification consumer — task-3-brief.md D1. Dispatched
 * onto the `notifications` queue
 * (`App\Platform\Outbox\OutboxQueueName::Notifications`) by
 * `Listeners\DispatchNotificationConsumerOnOutboxEventPublished`, a plain,
 * non-queued listener on `App\Platform\Outbox\Events\OutboxEventPublished`
 * registered in `Providers\NotificationServiceProvider`.
 *
 * Takes the outbox event's `id` (a plain string), not the model or the
 * envelope array — the same re-fetch-fresh-state pattern
 * `App\Platform\Outbox\Jobs\PublishOutboxEventJob` uses and documents.
 * `Actions\DispatchNotification::consumeOutboxEvent()` does the actual
 * re-read and every write; this job is a thin queue entry point only.
 *
 * `$matrixEventName` (nullable, default) is an OPTIONAL explicit template
 * selection, used when a single outbox event maps ambiguously to more than
 * one matrix row and the discriminator lives outside the platform. The
 * order-lifecycle bridge `App\Domain\OrderWorkflow\Listeners\
 * DispatchOrderNotifications` dispatches this job for the canonical
 * `order.status_changed.v1` event with the matrix label resolved from the
 * payload's `to_status` ("Booking submitted"/"Order processing"/"Order
 * completed") — "Order processing" and "Order completed" keep a NULL
 * `outbox_event_name` (Wave-1a ruling 1 left the status-discrimination
 * question open; the bridge resolves it); "Booking submitted" carries a
 * non-null `outbox_event_name` that is simply unused for this row, since
 * the lookup here is always by the explicit `$matrixEventName` argument
 * (see `DispatchOrderNotifications`'s own doc block). Every existing
 * caller outside that bridge leaves it null and keeps the
 * `outbox_event_name` lookup.
 */
final class ConsumeOutboxNotificationJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $outboxEventId,
        public readonly ?string $matrixEventName = null,
    ) {}

    public function handle(DispatchNotification $dispatcher): void
    {
        $dispatcher->consumeOutboxEvent($this->outboxEventId, $this->matrixEventName);
    }
}
