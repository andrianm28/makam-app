<?php

declare(strict_types=1);

namespace App\Domain\Memorial\Models;

use App\Domain\Memorial\MemorialModerationState;
use App\Platform\DocumentVault\DocumentState;
use App\Platform\DocumentVault\Models\Document;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;

/**
 * Eloquent model for `memorial_media` — family-authored media (AC6),
 * fail-closed by construction.
 *
 * `storage_ref` is the platform document vault's `documents.id` UUID —
 * a private reference, never a file path and never the media's bytes.
 * The `creating` guard refuses any row whose `storage_ref` does not
 * resolve to a vault document in `DocumentState::Accepted`: a scan that
 * has not completed or has failed is never usable (design.md's Error
 * handling section — `memorial_media` carries moderation_state, and the
 * ACCEPTED requirement is enforced against the vault row, not
 * re-declared here).
 */
final class MemorialMedia extends Model
{
    use HasUuids;

    protected $table = 'memorial_media';

    /**
     * Model-level default so the saving guard sees a known value even
     * when a caller creates a row without setting `moderation_state`
     * (the database default alone would apply only at INSERT, after the
     * guard has already run).
     *
     * @var array<string, string>
     */
    protected $attributes = [
        'moderation_state' => MemorialModerationState::DEFAULT,
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'memorial_profile_id',
        'storage_ref',
        'moderation_state',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'moderation_state' => 'string',
        ];
    }

    protected static function booted(): void
    {
        self::saving(function (self $media): void {
            MemorialModerationState::assertKnown((string) $media->moderation_state);
        });

        self::creating(function (self $media): void {
            $usable = Document::query()
                ->whereKey($media->storage_ref)
                ->where('state', DocumentState::Accepted->value)
                ->exists();

            if (! $usable) {
                throw new InvalidArgumentException(
                    "Cannot attach memorial media referencing document [{$media->storage_ref}]: ".
                    'only accepted vault documents are usable.'
                );
            }
        });
    }

    /**
     * @return BelongsTo<MemorialProfile, $this>
     */
    public function profile(): BelongsTo
    {
        return $this->belongsTo(MemorialProfile::class, 'memorial_profile_id');
    }
}
