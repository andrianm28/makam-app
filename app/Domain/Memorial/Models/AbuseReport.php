<?php

declare(strict_types=1);

namespace App\Domain\Memorial\Models;

use App\Platform\Audit\Audit;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;

/**
 * Eloquent model for `abuse_reports` — one row per reporter per case
 * (AC6). `reason` is REQUIRED: the action refuses a blank reason and
 * this model-level guard backstops every other write path with the same
 * Unicode-aware blank check `Audit::reasonIsBlank()` uses (a report of
 * one invisible character is indistinguishable from one nobody wrote).
 */
final class AbuseReport extends Model
{
    use HasUuids;

    protected $table = 'abuse_reports';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'moderation_case_id',
        'reporter_ref',
        'reason',
    ];

    protected static function booted(): void
    {
        self::saving(function (self $report): void {
            if (Audit::reasonIsBlank((string) $report->reason)) {
                throw new InvalidArgumentException('An abuse report requires a reason.');
            }
        });
    }

    /**
     * @return BelongsTo<ModerationCase, $this>
     */
    public function case(): BelongsTo
    {
        return $this->belongsTo(ModerationCase::class, 'moderation_case_id');
    }
}
