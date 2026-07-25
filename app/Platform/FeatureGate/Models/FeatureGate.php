<?php

declare(strict_types=1);

namespace App\Platform\FeatureGate\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Eloquent model for `feature_gates` — see the migration
 * (`2026_07_26_120000_create_feature_gates_table.php`) for the schema and
 * `2026_07_26_120400_seed_feature_gate_registry.php` for the 17 seeded
 * rows, derived from `docs/governance/assumptions-and-gates.md` §2.
 *
 * This model is a plain row accessor. It does NOT decide open/closed for a
 * request — that decision (including the deny-by-default rule for a row
 * that fails to load or holds an invalid `state`) belongs to
 * `FeatureGateResolver` / `EloquentGateRegistrySource`, never here. Reading
 * `->isOpenState()` below tells you what this one row's `state` column
 * literally says; it does not account for environment overrides, an
 * unknown gate id, or a malformed row — see those classes for the real
 * evaluation.
 */
final class FeatureGate extends Model
{
    protected $primaryKey = 'gate_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'gate_id',
        'capability',
        'type',
        'owner',
        'state',
        'evidence_reference',
        'effective_at',
        'rollback_path',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'effective_at' => 'immutable_datetime',
        ];
    }

    public function activations(): HasMany
    {
        return $this->hasMany(GateActivation::class, 'gate_id', 'gate_id');
    }

    public function environmentStates(): HasMany
    {
        return $this->hasMany(GateEnvironmentState::class, 'gate_id', 'gate_id');
    }

    /**
     * Literal reading of this row's own `state` column — 'open' vs
     * anything else. Deliberately NOT named `isOpen()`: that name is
     * reserved for `FeatureGateResolver`'s deny-by-default-aware method, so
     * nobody mistakes this narrower check for the real evaluation.
     */
    public function isOpenState(): bool
    {
        return $this->state === 'open';
    }
}
