<?php

declare(strict_types=1);

namespace App\Platform\Outbox;

/**
 * The exact queue names/priorities named by `queue-and-outbox.md` §2 —
 * copied here as literal string values only (this is operational routing
 * metadata, not a restatement of `event-catalog.md`'s event types, which
 * AC3 forbids). One enum case per row of that table, same order.
 */
enum OutboxQueueName: string
{
    case Critical = 'critical';
    case Urgent = 'urgent';
    case Notifications = 'notifications';
    case Default = 'default';
    case Imports = 'imports';
    case Media = 'media';
    case Reports = 'reports';
}
