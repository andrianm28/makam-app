<?php

declare(strict_types=1);

namespace App\Platform\FeatureGate\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Eloquent model for `gate_activations` — see the migration
 * (`2026_07_26_120300_create_gate_activations_table.php`) for the schema
 * and the append-only contract.
 *
 * Rows are written ONLY through `GateActivationRecorder`, never assigned
 * and `->save()`-d directly by a caller — the recorder is what enforces
 * "no activation without evidence and reason" (requirements.md Negative
 * criteria) before a row ever reaches the database. This model disables
 * Eloquent's `updated_at` timestamp entirely (`UPDATED_AT = null`) so an
 * accidental `->update()` call on an existing row cannot even touch a
 * timestamp column that does not exist — the migration itself has no
 * `updated_at` column, so such a call would fail loudly instead of
 * silently rewriting history.
 */
final class GateActivation extends Model
{
    /**
     * No `updated_at` column exists on this append-only table — see class
     * doc block.
     */
    const UPDATED_AT = null;

    protected $fillable = [
        'gate_id',
        'actor_reference',
        'evidence_reference',
        'reason',
        'from_state',
        'to_state',
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

    /**
     * @return BelongsTo<FeatureGate, $this>
     */
    public function gate(): BelongsTo
    {
        return $this->belongsTo(FeatureGate::class, 'gate_id', 'gate_id');
    }
}
