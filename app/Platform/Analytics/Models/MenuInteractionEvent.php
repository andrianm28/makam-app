<?php

declare(strict_types=1);

namespace App\Platform\Analytics\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Eloquent model for `menu_interaction_events`
 * (`2026_07_26_200000_create_menu_interaction_events_table.php` — see that
 * migration's own doc block for the full AC9 scope and honesty framing).
 *
 * Construct rows only via `App\Platform\Analytics\MenuInteractionRecorder`,
 * never directly — mirrors `App\Platform\Audit\Models\AuditEvent`'s own
 * "one write API" convention, though deliberately without that class's
 * append-only enforcement machinery: an anonymous count of menu views is
 * not a legal/compliance audit trail, so there is no `AuditRecordIsImmutableException`-style
 * guard here. Getting a count wrong is a data-quality bug, not an integrity
 * incident.
 */
final class MenuInteractionEvent extends Model
{
    /**
     * No `updated_at` — an interaction event is never revised after it is
     * written, so a column implying otherwise would be misleading. Same
     * reasoning as `AuditEvent::$timestamps`, restated here rather than
     * inherited since this model does not extend that one (different
     * concerns — see the migration's doc block).
     */
    public $timestamps = false;

    protected $table = 'menu_interaction_events';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'menu_key',
        'route',
        'interaction',
        'occurred_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'occurred_at' => 'immutable_datetime',
        ];
    }
}
