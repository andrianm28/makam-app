<?php

declare(strict_types=1);

namespace App\Platform\Notification\Listeners;

use App\Platform\Notification\DemoDataSuppression;
use App\Platform\Notification\Jobs\ConsumeOutboxNotificationJob;
use App\Platform\Outbox\Events\OutboxEventPublished;
use App\Platform\Outbox\OutboxQueueName;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * task-3-brief.md D1: there is no notification classification in
 * `App\Platform\Outbox\OutboxClassification` (its four values are
 * `PUBLIC|INTERNAL|CONFIDENTIAL|RESTRICTED`) — an outbox event is
 * notification-classified iff a `notification_templates` row exists whose
 * `outbox_event_name` equals the envelope's `event_name`. No matching row
 * means nothing is sent, silently and correctly (`outbox_event_name` is
 * NULL on 11 of the 17 matrix rows by design).
 *
 * A PLAIN, non-queued listener — registered via `Event::listen()`, not
 * implementing `ShouldQueue` — because `App\Platform\Outbox\Jobs\
 * PublishOutboxEventJob::handle()` fires `OutboxEventPublished`
 * SYNCHRONOUSLY; a queued listener would put the lookup-and-dispatch below
 * on the wrong queue, one hop later than it needs to be. Deliberately kept
 * thin — lookup + dispatch, nothing else — the actual consumption logic
 * lives in `Actions\DispatchNotification::consumeOutboxEvent()`, run by the
 * job this listener dispatches.
 */
final class DispatchNotificationConsumerOnOutboxEventPublished
{
    public function handle(OutboxEventPublished $event): void
    {
        $eventId = $event->envelope['event_id'] ?? null;
        $eventName = $event->envelope['event_name'] ?? null;

        if (! is_string($eventId) || ! is_string($eventName)) {
            return;
        }

        $isNotificationClassified = DB::table('notification_templates')
            ->where('outbox_event_name', $eventName)
            ->exists();

        if (! $isNotificationClassified) {
            return;
        }

        if (DemoDataSuppression::active()) {
            Log::info('notification.suppressed_for_demo_seeding', [
                'outbox_event_id' => $eventId,
                'matrix_event_name' => $eventName,
            ]);

            return;
        }

        ConsumeOutboxNotificationJob::dispatch($eventId)
            ->onQueue(OutboxQueueName::Notifications->value);
    }
}
