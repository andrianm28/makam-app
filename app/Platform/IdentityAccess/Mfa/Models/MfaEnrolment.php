<?php

declare(strict_types=1);

namespace App\Platform\IdentityAccess\Mfa\Models;

use App\Models\User;
use App\Platform\IdentityAccess\Mfa\MfaEnrolmentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Eloquent model for `mfa_enrolments` — see the migration
 * (`2026_07_26_150000_create_mfa_enrolments_table.php`) for schema and
 * column-shape reasoning.
 *
 * Rows are written ONLY through `Mfa\MfaEnrolmentService` — never
 * constructed and `->save()`-d directly by any other caller — because that
 * service is what enforces the pending -> confirmed lifecycle, the
 * one-verified-code-required-to-confirm rule, and generates recovery codes
 * atomically alongside confirmation. See that service's own doc block.
 *
 * `secret` uses Laravel's built-in `encrypted` Eloquent cast
 * (`Illuminate\Database\Eloquent\Casts\AsEncryptedCollection`'s sibling,
 * the plain `'encrypted'` cast alias — encrypts on write via the
 * application's `APP_KEY`-derived encrypter, decrypts transparently on
 * read). VERIFICATION STATUS: this cast alias has existed in Laravel since
 * 7.x and is documented in Laravel's own "Eloquent: Mutators & Casting"
 * page as `'secret' => 'encrypted'`; this host has no installed
 * `vendor/laravel/framework` to grep against (same empty-`vendor/`
 * constraint noted elsewhere in this codebase, e.g. `User::canAccessPanel()`
 * 's own doc block) — confirmed against Laravel's documented public API,
 * not verified against installed source on this host.
 */
final class MfaEnrolment extends Model
{
    protected $table = 'mfa_enrolments';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'secret',
        'status',
        'digits',
        'period_seconds',
        'last_verified_counter',
        'confirmed_at',
        'revoked_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'secret' => 'encrypted',
            'digits' => 'integer',
            'period_seconds' => 'integer',
            'last_verified_counter' => 'integer',
            'confirmed_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        self::saving(function (self $enrolment): void {
            MfaEnrolmentStatus::assertKnown($enrolment->status);
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function recoveryCodes(): HasMany
    {
        return $this->hasMany(MfaRecoveryCode::class);
    }

    public function challenges(): HasMany
    {
        return $this->hasMany(MfaChallenge::class);
    }

    public function isPending(): bool
    {
        return $this->status === MfaEnrolmentStatus::PENDING;
    }

    public function isConfirmed(): bool
    {
        return $this->status === MfaEnrolmentStatus::CONFIRMED;
    }

    public function isRevoked(): bool
    {
        return $this->status === MfaEnrolmentStatus::REVOKED;
    }
}
