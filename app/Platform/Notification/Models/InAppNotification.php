<?php

declare(strict_types=1);

namespace App\Platform\Notification\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Eloquent accessor for `in_app_notifications`
 * (`2026_08_09_100060_create_in_app_notifications_table.php`). The only
 * writer is `App\Platform\Notification\Actions\RecordInAppNotification` —
 * see that migration's own doc block for AC7's unconditional-record
 * requirement. `read_at` is written by `Actions\MarkInAppNotificationRead`.
 */
final class InAppNotification extends Model
{
    protected $table = 'in_app_notifications';

    protected $guarded = ['*'];

    /**
     * The delivery-state rows for this notification's event, read from
     * `notification_deliveries` — the AC4 source of truth the inbox renders
     * from (task-5-brief.md Step 3). An in-app row and its deliveries share
     * `event_id`, not a foreign key between the two tables.
     *
     * The relation is scoped on `event_id` ONLY, and deliberately does NOT
     * add `where('recipient_ref', $this->recipient_ref)`: during eager
     * loading (`->with('deliveries')`, which `InAppNotificationList::render()`
     * uses) Eloquent instantiates the relation from the builder's fresh,
     * attribute-less model, so `$this->recipient_ref` is `null` and the
     * eager query matches nothing — a recipient-scoped where in this method
     * silently returns an empty collection in production. One event carries
     * delivery rows for every resolved recipient (customer AND operator AND
     * vendor), but an inbox chip labels only a channel and a state, with no
     * recipient identity — so the per-row recipient filter happens in the
     * inbox view (`in-app-notification-list.blade.php`), on the already
     * eager-loaded collection, where it is correct for both eager and lazy
     * loading.
     */
    public function deliveries(): HasMany
    {
        return $this->hasMany(NotificationDelivery::class, 'event_id', 'event_id')
            ->orderBy('id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'read_at' => 'immutable_datetime',
        ];
    }
}
