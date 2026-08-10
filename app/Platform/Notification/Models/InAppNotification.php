<?php

declare(strict_types=1);

namespace App\Platform\Notification\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Eloquent accessor for `in_app_notifications`
 * (`2026_08_09_100060_create_in_app_notifications_table.php`). The only
 * writer is `App\Platform\Notification\Actions\RecordInAppNotification` —
 * see that migration's own doc block for AC7's unconditional-record
 * requirement.
 */
final class InAppNotification extends Model
{
    protected $table = 'in_app_notifications';

    protected $guarded = ['*'];

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
