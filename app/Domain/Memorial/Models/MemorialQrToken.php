<?php

declare(strict_types=1);

namespace App\Domain\Memorial\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Eloquent model for `memorial_qr_tokens` — the opaque, revocable QR
 * token (AC4).
 *
 * `issueFor()` is the ONLY way a token is created, and the token is
 * `Str::random(48)` — random, NEVER derived from `memorial_profile_id`
 * or any other identifier (deriving would make tokens guessable against
 * a known profile: the enumeration risk AC4 exists to close). The token
 * column also carries its own unique index as a database backstop.
 *
 * Rotation mints a NEW row and mutates the old one in place
 * (`revoked_at` + `rotated_at`) — never mutates the token string — so
 * the old physical QR code fails exactly like a forgery. The partial
 * unique index on `(memorial_profile_id) WHERE revoked_at IS NULL`
 * guarantees one active token per profile and releases on revoke.
 */
final class MemorialQrToken extends Model
{
    use HasUuids;

    protected $table = 'memorial_qr_tokens';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'memorial_profile_id',
        'token',
        'revoked_at',
        'rotated_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'revoked_at' => 'immutable_datetime',
            'rotated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<MemorialProfile, $this>
     */
    public function profile(): BelongsTo
    {
        return $this->belongsTo(MemorialProfile::class, 'memorial_profile_id');
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null;
    }

    /**
     * Mint a new random token for the profile. NOT idempotent: the
     * caller (normally `RotateMemorialQrToken`) must revoke the current
     * active token first — the partial unique index refuses a second
     * active token as the database backstop.
     */
    public static function issueFor(MemorialProfile $profile): self
    {
        return self::query()->create([
            'memorial_profile_id' => $profile->getKey(),
            'token' => Str::random(48),
        ]);
    }

    /**
     * The profile's current active (non-revoked) token, if any — the
     * lookup `RotateMemorialQrToken` and the family surface use.
     */
    public static function activeFor(MemorialProfile $profile): ?self
    {
        return self::query()
            ->where('memorial_profile_id', $profile->getKey())
            ->whereNull('revoked_at')
            ->first();
    }

    /**
     * The revocation transition. Idempotent: re-revoking an
     * already-revoked token is a no-op success (design.md's Error
     * handling section).
     */
    public function revoke(): void
    {
        if (! $this->isActive()) {
            return;
        }

        $this->revoked_at = now();
        $this->save();
    }
}
