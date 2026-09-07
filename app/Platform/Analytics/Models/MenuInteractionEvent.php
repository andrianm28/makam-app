<?php

declare(strict_types=1);

namespace App\Platform\Analytics\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;

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
 *
 * PERF-06 — `Prunable`: this table is written on every homepage view
 * (`App\Livewire\Public\HomePage::mount()`, via `App\Jobs\
 * RecordMenuImpressions`) and, before this fix, was never pruned — an
 * unbounded, ever-growing write-only table. `RETENTION_DAYS` (180) keeps
 * roughly six months of the anonymous count, long enough for any
 * seasonal-comparison use this data might someday get, while guaranteeing
 * the table does not grow forever. Pruned daily by
 * `routes/console.php`'s `model:prune` schedule entry.
 */
final class MenuInteractionEvent extends Model
{
    use Prunable;

    private const RETENTION_DAYS = 180;

    public function prunable(): Builder
    {
        return self::query()->where('occurred_at', '<', now()->subDays(self::RETENTION_DAYS));
    }

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
