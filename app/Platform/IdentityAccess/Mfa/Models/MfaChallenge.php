<?php

declare(strict_types=1);

namespace App\Platform\IdentityAccess\Mfa\Models;

use App\Platform\IdentityAccess\Mfa\MfaChallengeOutcome;
use App\Platform\IdentityAccess\Mfa\MfaVerificationMethod;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Eloquent model for `mfa_challenges` — see the migration
 * (`2026_07_26_150200_create_mfa_challenges_table.php`) for schema and
 * exactly why one table serves both TOTP challenges and recovery-code
 * redemptions (the `method` column).
 *
 * Rows are written ONLY through `Mfa\MfaChallengeService` and
 * `Mfa\MfaRecoveryService` — one row per verification attempt, succeeded or
 * failed. Never carries a code, secret, or recovery code value — see the
 * migration's own doc block for the Negative-criteria reasoning.
 */
final class MfaChallenge extends Model
{
    protected $table = 'mfa_challenges';

    /**
     * Neither `created_at` nor `updated_at` exists on this table — only
     * `occurred_at` (an explicit, server-set fillable column) — matching
     * `App\Platform\Audit\Models\AuditEvent`'s identical choice for the
     * identical reason: this is an append-only attempt log, not a record
     * meant to look editable.
     */
    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'mfa_enrolment_id',
        'method',
        'outcome',
        'ip_address',
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

    protected static function booted(): void
    {
        self::saving(function (self $challenge): void {
            MfaVerificationMethod::assertKnown($challenge->method);
            MfaChallengeOutcome::assertKnown($challenge->outcome);
        });
    }

    /**
     * @return BelongsTo<MfaEnrolment, $this>
     */
    public function enrolment(): BelongsTo
    {
        return $this->belongsTo(MfaEnrolment::class, 'mfa_enrolment_id');
    }

    public function succeeded(): bool
    {
        return $this->outcome === MfaChallengeOutcome::SUCCEEDED;
    }
}
