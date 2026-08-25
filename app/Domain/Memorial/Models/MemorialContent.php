<?php

declare(strict_types=1);

namespace App\Domain\Memorial\Models;

use App\Domain\Memorial\MemorialModerationState;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Eloquent model for `memorial_contents` — one family-authored message
 * (AC6). Moderation is a STATE TRANSITION, never a row deletion: the
 * row keeps its full history so the moderation trail survives. Only
 * `approved` rows render in `MemorialPublicProjection`.
 */
final class MemorialContent extends Model
{
    use HasUuids;

    protected $table = 'memorial_contents';

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
        'body',
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
        self::saving(function (self $content): void {
            MemorialModerationState::assertKnown((string) $content->moderation_state);
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
