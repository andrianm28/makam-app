<?php

declare(strict_types=1);

namespace App\Platform\Notification;

use App\Platform\Outbox\OutboxQueueName;

/**
 * Queue priorities used by notification work. The numeric values mirror the
 * canonical queue table; the queue method keeps routing in one closed list.
 */
enum NotificationPriority: int
{
    case External = 3;
    case Operational = 4;

    public function queue(): OutboxQueueName
    {
        return match ($this) {
            self::External => OutboxQueueName::Notifications,
            self::Operational => OutboxQueueName::Default,
        };
    }
}
