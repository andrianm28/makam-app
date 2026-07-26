<?php

declare(strict_types=1);

namespace App\Platform\FeatureGate\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Eloquent model for `feature_flags` — see the migration
 * (`2026_07_26_120100_create_feature_flags_table.php`) for the schema and
 * `2026_07_26_120400_seed_feature_gate_registry.php` for the 18 seeded
 * rows, derived from `docs/operations/feature-flag-registry.md`.
 *
 * Like `FeatureGate`, this model does not itself decide anything for a
 * request — flags declare a default and a prerequisite gate; whether a
 * flag is actually "on" for the current request is a `FeatureGateResolver`
 * concern (a flag whose prerequisite gate resolves closed is off,
 * regardless of `default_enabled`).
 */
final class FeatureFlag extends Model
{
    protected $primaryKey = 'flag_key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'flag_key',
        'default_enabled',
        'owner',
        'prerequisite_gate_id',
        'prerequisite_note',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'default_enabled' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<FeatureGate, $this>
     */
    public function prerequisiteGate(): BelongsTo
    {
        return $this->belongsTo(FeatureGate::class, 'prerequisite_gate_id', 'gate_id');
    }
}
