<?php

declare(strict_types=1);

namespace App\Platform\Notification\Models;

use App\Platform\Notification\Exceptions\NotificationTemplateVersionIsImmutableException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Immutable render snapshot for `notification_templates`. Sent deliveries
 * will pin one of these rows; changing the active template creates another
 * row and never rewrites an existing snapshot.
 */
final class NotificationTemplateVersion extends Model
{
    protected $table = 'notification_template_versions';

    public $timestamps = false;

    protected $guarded = ['*'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'template_id' => 'integer',
            'version' => 'integer',
            'variable_allowlist' => 'array',
            'restricted_fields' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        self::saving(function (self $version): void {
            if ($version->exists) {
                throw NotificationTemplateVersionIsImmutableException::forOperation('save');
            }
        });

        self::deleting(function (self $version): void {
            throw NotificationTemplateVersionIsImmutableException::forOperation('delete');
        });
    }

    /**
     * Always throws for the explicit Eloquent update path. The saving hook
     * covers attribute assignment followed by save().
     */
    public function update(array $attributes = [], array $options = []): bool
    {
        throw NotificationTemplateVersionIsImmutableException::forOperation('update');
    }

    /**
     * @return BelongsTo<NotificationTemplate, $this>
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(NotificationTemplate::class, 'template_id');
    }

    /**
     * `saving` is the model-level guard for ordinary Eloquent writes. This
     * method is retained as an explicit defense for the direct save path.
     */
    protected function performUpdate(Builder $query): bool
    {
        throw NotificationTemplateVersionIsImmutableException::forOperation('performUpdate');
    }
}
