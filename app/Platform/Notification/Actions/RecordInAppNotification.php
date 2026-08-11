<?php

declare(strict_types=1);

namespace App\Platform\Notification\Actions;

use App\Platform\Notification\Models\InAppNotification;
use App\Platform\Notification\Recipient;

/**
 * The ONE write API for `in_app_notifications` — AC7
 * (`AGENTS.md` §Notifications: "Always create relevant admin/operator/
 * vendor in-app records using record scope"). Called by
 * `DispatchNotification::consumeOutboxEvent()` for every resolved
 * `PLATFORM_ADMIN`/`CEMETERY_OPERATOR`/`VENDOR` recipient, INSIDE the same
 * transaction as that event's `notification_events` row — never from
 * inside a per-channel job, so a later external-channel failure can never
 * erase an already-committed in-app record (AC5/AC7).
 */
final class RecordInAppNotification
{
    public function __invoke(string $eventId, Recipient $recipient, ?string $subject, string $body): InAppNotification
    {
        $notification = new InAppNotification;

        $notification->forceFill([
            'event_id' => $eventId,
            'recipient_ref' => (string) $recipient->actorRef,
            'actor_role' => $recipient->actorRole,
            'scope_entity_type' => $recipient->scopeEntityType,
            'scope_entity_id' => $recipient->scopeEntityId !== null ? (string) $recipient->scopeEntityId : null,
            'subject' => $subject,
            'body' => $body,
        ])->save();

        return $notification;
    }
}
