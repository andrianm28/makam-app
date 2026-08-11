<?php

declare(strict_types=1);

namespace App\Platform\Notification\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Eloquent accessor for `notification_events`
 * (`2026_08_09_100030_create_notification_events_table.php`). The only
 * writer is `App\Platform\Notification\Actions\DispatchNotification::
 * consumeOutboxEvent()` — see that migration's own doc block for why
 * `event_id` (the outbox row id) is this table's primary key and its
 * idempotency anchor.
 */
final class NotificationEvent extends Model
{
    protected $table = 'notification_events';

    protected $primaryKey = 'event_id';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = ['*'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'consumed_at' => 'immutable_datetime',
        ];
    }
}
