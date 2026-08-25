<?php

declare(strict_types=1);

namespace App\Domain\Memorial\Models;

use App\Domain\GraveRegistry\Models\GraveRecord;
use App\Domain\Memorial\MemorialPrivacyMode;
use App\Platform\IdentityAccess\ActorContext;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Eloquent model for `memorial_profiles` — the root aggregate of the
 * Memorial module (`.kiro/specs/memorial-and-qr/design.md`'s Components
 * section). Backs requirements.md AC1 (private default), AC2 (privacy
 * modes), AC4 (QR token anchoring) and AC7 (the grave-record boundary).
 *
 * ---------------------------------------------------------------------------
 * AC7 — `grave_record_id` is the ONLY link to GraveRegistry
 * ---------------------------------------------------------------------------
 * Nothing on this model is copied from the grave record and nothing
 * writes back to it: the two lifecycles stay independent. `display_name`
 * is family-authored content set at create/publish time — it is NEVER
 * auto-derived from `grave_records.deceased_name` (the creation action
 * never reads the grave record's name field).
 *
 * ---------------------------------------------------------------------------
 * No public read through this model
 * ---------------------------------------------------------------------------
 * Same discipline as `GraveRecord`: a caller holding a
 * `MemorialProfile` instance holds every column. The public surface
 * resolves through `MemorialPublicProjection` (an allowlist value
 * object), never through this model — see `ResolveMemorialQr`.
 */
final class MemorialProfile extends Model
{
    use HasUuids;

    protected $table = 'memorial_profiles';

    /**
     * Model-level default so the saving guard sees a known value even
     * when a caller creates a row without setting `privacy_mode`
     * (the database default alone would apply only at INSERT, after the
     * guard has already run).
     *
     * @var array<string, string>
     */
    protected $attributes = [
        'privacy_mode' => MemorialPrivacyMode::DEFAULT,
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'grave_record_id',
        'display_name',
        'privacy_mode',
        'published_at',
        'unpublished_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'privacy_mode' => 'string',
            'published_at' => 'immutable_datetime',
            'unpublished_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        self::saving(function (self $profile): void {
            MemorialPrivacyMode::assertKnown((string) $profile->privacy_mode);
        });

        self::deleting(function (self $profile): void {
            $child = (int) DB::table('memorial_editors')->where('memorial_profile_id', $profile->getKey())->count()
                + (int) DB::table('memorial_contents')->where('memorial_profile_id', $profile->getKey())->count()
                + (int) DB::table('memorial_media')->where('memorial_profile_id', $profile->getKey())->count()
                + (int) DB::table('memorial_qr_tokens')->where('memorial_profile_id', $profile->getKey())->count()
                + (int) DB::table('moderation_cases')->where('memorial_profile_id', $profile->getKey())->count();

            if ($child > 0) {
                throw new InvalidArgumentException(
                    "Cannot delete memorial profile [{$profile->getKey()}]: editors, contents, media, ".
                    'QR tokens, or moderation cases exist for it. Deletion follows the approved '.
                    'retention policy (memorial-and-qr AC8), not a plain delete.'
                );
            }
        });
    }

    /**
     * @return BelongsTo<GraveRecord, $this>
     */
    public function graveRecord(): BelongsTo
    {
        return $this->belongsTo(GraveRecord::class, 'grave_record_id');
    }

    /**
     * @return HasMany<MemorialEditor, $this>
     */
    public function editors(): HasMany
    {
        return $this->hasMany(MemorialEditor::class, 'memorial_profile_id');
    }

    /**
     * @return HasMany<MemorialContent, $this>
     */
    public function contents(): HasMany
    {
        return $this->hasMany(MemorialContent::class, 'memorial_profile_id');
    }

    /**
     * @return HasMany<MemorialMedia, $this>
     */
    public function media(): HasMany
    {
        return $this->hasMany(MemorialMedia::class, 'memorial_profile_id');
    }

    /**
     * @return HasMany<MemorialQrToken, $this>
     */
    public function qrTokens(): HasMany
    {
        return $this->hasMany(MemorialQrToken::class, 'memorial_profile_id');
    }

    /**
     * @return HasMany<ModerationCase, $this>
     */
    public function moderationCases(): HasMany
    {
        return $this->hasMany(ModerationCase::class, 'memorial_profile_id');
    }

    /**
     * AC1/AC2's visibility decision, in ONE place.
     *
     * `$hasToken` is true for every QR resolver (the caller physically
     * holds the token); `$actor` is null for a guest request. The matrix:
     *
     * | mode       | guest with token | any actor with token | active editor |
     * |------------|------------------|----------------------|---------------|
     * | public     | visible          | visible              | visible       |
     * | unlisted   | visible          | visible              | visible       |
     * | family_only| NOT visible      | NOT visible          | visible       |
     * | private    | NOT visible      | NOT visible          | visible       |
     *
     * An active editor is an `memorial_editors` row for
     * `$actor->identityReference` with `revoked_at` null.
     */
    public function isVisibleTo(?ActorContext $actor, bool $hasToken): bool
    {
        return match ((string) $this->privacy_mode) {
            MemorialPrivacyMode::PUBLIC->value => true,
            MemorialPrivacyMode::UNLISTED->value => $hasToken,
            MemorialPrivacyMode::FAMILY_ONLY->value => $hasToken && $this->hasActiveEditor($actor),
            MemorialPrivacyMode::PRIVATE->value => $this->hasActiveEditor($actor),
            default => false,
        };
    }

    /**
     * `true` when `$actor` (null for guests) is an active editor of this
     * profile. The one predicate both FAMILY_ONLY and PRIVATE build on.
     */
    public function hasActiveEditor(?ActorContext $actor): bool
    {
        if ($actor === null || $actor->identityReference === null) {
            return false;
        }

        return $this->editors()
            ->where('actor_id', (string) $actor->identityReference)
            ->whereNull('revoked_at')
            ->exists();
    }
}
