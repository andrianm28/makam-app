<?php

declare(strict_types=1);

namespace App\Platform\Notification\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Eloquent accessor for `notification_recipients`
 * (`2026_08_09_100040_create_notification_recipients_table.php`). The only
 * writer is `App\Platform\Notification\Actions\DispatchNotification::
 * consumeOutboxEvent()` — see that migration's own doc block for why this
 * table needs no unique constraint of its own beyond the
 * `notification_events` idempotency anchor.
 */
final class NotificationRecipient extends Model
{
    protected $table = 'notification_recipients';

    public $timestamps = false;

    protected $guarded = ['*'];
}
