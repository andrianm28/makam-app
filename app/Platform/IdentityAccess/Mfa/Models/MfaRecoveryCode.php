<?php

declare(strict_types=1);

namespace App\Platform\IdentityAccess\Mfa\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Eloquent model for `mfa_recovery_codes` — see the migration
 * (`2026_07_26_150100_create_mfa_recovery_codes_table.php`) for schema and
 * the dedicated-table-vs-JSON-column reasoning.
 *
 * Rows are written ONLY through `Mfa\MfaEnrolmentService::confirm()`
 * (generation) and consumed ONLY through `Mfa\MfaRecoveryService::redeem()`
 * (marking `used_at`). `code_hash` is the ONLY form a recovery code ever
 * takes at rest — the plaintext code is generated, hashed, and returned to
 * the caller ONCE by `confirm()`, never persisted in plaintext anywhere.
 */
final class MfaRecoveryCode extends Model
{
    protected $table = 'mfa_recovery_codes';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'mfa_enrolment_id',
        'code_hash',
        'used_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'used_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<MfaEnrolment, $this>
     */
    public function enrolment(): BelongsTo
    {
        return $this->belongsTo(MfaEnrolment::class, 'mfa_enrolment_id');
    }

    public function isUsed(): bool
    {
        return $this->used_at !== null;
    }

    /**
     * Mark this code consumed. Never called twice for the same row in
     * practice — `Mfa\MfaRecoveryService::redeem()` only ever queries
     * `used_at IS NULL` rows as redemption candidates in the first place.
     */
    public function markUsed(): void
    {
        $this->forceFill(['used_at' => CarbonImmutable::now()])->save();
    }
}
