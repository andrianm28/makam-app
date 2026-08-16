<?php

declare(strict_types=1);

namespace App\Domain\Memorial\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Eloquent model for `moderation_cases` — report intake + moderator
 * resolution (AC6). `reported_content_type`/`reported_content_id`
 * reference the reported row polymorphically (`memorial_contents` or
 * `memorial_media`); the case anchors on the profile for moderation
 * scoping and carries one or more `abuse_reports` (one per reporter).
 */
final class ModerationCase extends Model
{
    use HasUuids;

    public const string STATUS_OPEN = 'open';

    public const string STATUS_RESOLVED = 'resolved';

    public const string STATUS_DISMISSED = 'dismissed';

    protected $table = 'moderation_cases';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'memorial_profile_id',
        'reported_content_type',
        'reported_content_id',
        'status',
    ];

    /**
     * @return BelongsTo<MemorialProfile, $this>
     */
    public function profile(): BelongsTo
    {
        return $this->belongsTo(MemorialProfile::class, 'memorial_profile_id');
    }

    /**
     * @return HasMany<AbuseReport, $this>
     */
    public function abuseReports(): HasMany
    {
        return $this->hasMany(AbuseReport::class, 'moderation_case_id');
    }
}
