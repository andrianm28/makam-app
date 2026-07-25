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
