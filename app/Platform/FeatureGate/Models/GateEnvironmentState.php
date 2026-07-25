<?php

declare(strict_types=1);

namespace App\Platform\FeatureGate\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Eloquent model for `gate_environment_state` — see the migration
 * (`2026_07_26_120200_create_gate_environment_state_table.php`) for the
 * schema and the AC11 scope note on what this batch does and does not
 * implement.
 */
final class GateEnvironmentState extends Model
{
    /**
     * FIXED 26 Jul 2026: without this, Eloquent's naming convention
     * auto-pluralizes the class name to `gate_environment_states`, but the
     * migration creates `gate_environment_state` (singular — design.md's
     * own literal data-list naming, which the migration correctly
     * followed). First real Postgres test run failed with "relation
     * gate_environment_states does not exist" — SQLite's more permissive
     * query planning had not caught this locally-unrunnable mismatch.
     */
    protected $table = 'gate_environment_state';

    protected $fillable = [
        'gate_id',
        'environment',
        'state',
    ];

    public function gate(): BelongsTo
    {
        return $this->belongsTo(FeatureGate::class, 'gate_id', 'gate_id');
    }

    public function isOpenState(): bool
    {
        return $this->state === 'open';
    }
}
