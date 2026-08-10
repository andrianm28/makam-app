<?php

declare(strict_types=1);

namespace App\Platform\Notification\Models;

use App\Platform\Notification\DeliveryState;
use Illuminate\Database\Eloquent\Model;

/**
 * Eloquent accessor for `notification_deliveries`
 * (`2026_08_09_100050_create_notification_deliveries_table.php`).
 *
 * ---------------------------------------------------------------------------
 * AC9 — the one write API, by construction
 * ---------------------------------------------------------------------------
 * `App\Platform\Notification\Actions\DispatchNotification` is the ONLY
 * class in this codebase that writes a row to this table — the initial
 * `QUEUED`/`UNAVAILABLE` insert (`consumeOutboxEvent()`) and every later
 * claim/state transition (`SendNotificationChannelJob`/`recordChannelOutcome()`). This is
 * enforced by CONVENTION (no other class calls `NotificationDelivery::
 * create()`/`::insert()`/`DB::table('notification_deliveries')->insert*()`)
 * and proven by `tests/Unit/Platform/Notification/
 * NotificationDeliveryWriteApiTest.php`'s structural scan, not by a
 * database trigger — unlike `NotificationTemplateVersion`'s immutability
 * guard, this table's rows are LEGITIMATELY mutated after insert (a
 * `QUEUED` row becomes `SENT`/`FAILED`), so a write-once trigger would be
 * the wrong tool; the property this class needs to protect is "who may
 * write," not "how many times."
 *
 * `provider_idempotency_key` is persisted from the durable delivery key. The
 * nullable `claim_token`/`claimed_at` lease is private dispatch metadata: it
 * prevents concurrent workers from entering the channel boundary together
 * while leaving `QUEUED` as the truthful pending state for the UI.
 */
final class NotificationDelivery extends Model
{
    protected $table = 'notification_deliveries';

    protected $guarded = ['*'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'state' => DeliveryState::class,
            'attempt_count' => 'integer',
            'claimed_at' => 'immutable_datetime',
        ];
    }
}
