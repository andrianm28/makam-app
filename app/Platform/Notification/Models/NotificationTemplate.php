<?php

declare(strict_types=1);

namespace App\Platform\Notification\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Eloquent accessor for `notification_templates` — see the migration and
 * `docs/contracts/notification-matrix.md`. Event rows are seeded from the
 * matrix; versions are append-only snapshots owned by the notification
 * authoring path.
 */
final class NotificationTemplate extends Model
{
    protected $table = 'notification_templates';

    public $timestamps = false;

    protected $guarded = ['*'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'active_version_id' => 'integer',
        ];
    }

    /**
     * @return HasMany<NotificationTemplateVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(NotificationTemplateVersion::class, 'template_id');
    }

    /**
     * @return BelongsTo<NotificationTemplateVersion, $this>
     */
    public function activeVersion(): BelongsTo
    {
        return $this->belongsTo(NotificationTemplateVersion::class, 'active_version_id');
    }
}
