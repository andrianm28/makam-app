<?php

declare(strict_types=1);

namespace App\Domain\Memorial\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Eloquent model for `memorial_visit_checkins` — one row per logged visit
 * affirmation (`docs/superpowers/specs/2026-09-05-memorial-visit-checkin-design.md`
 * §4.1). `note` is moderator-visible only in this batch — never rendered
 * on the public page or the family dashboard (see that section).
 */
final class MemorialVisitCheckin extends Model
{
    use HasUuids;

    protected $table = 'memorial_visit_checkins';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'memorial_profile_id',
        'checked_in_at',
        'visitor_label',
        'note',
        'moderation_state',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'checked_in_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<MemorialProfile, $this>
     */
    public function profile(): BelongsTo
    {
        return $this->belongsTo(MemorialProfile::class, 'memorial_profile_id');
    }
}
