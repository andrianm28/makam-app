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
 */
final class ConsumeOutboxNotificationJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $outboxEventId,
    ) {}

    public function handle(DispatchNotification $dispatcher): void
    {
        $dispatcher->consumeOutboxEvent($this->outboxEventId);
    }
}
