<?php

declare(strict_types=1);

namespace App\Domain\Visitation\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Eloquent model for `visitation_blackout_dates` — Task 1 of
 * `docs/superpowers/plans/2026-08-16-p4-memorial-qr-visitation.md`
 * (Lane 1 — Visitation). One row per (policy, date) the policy is
 * closed, with the visitor-visible reason surfaced by
 * `VisitationBlackoutDateException` and the public calendar tooltips.
 *
 * `reason` is guarded non-blank on save — same blank-rejection intent
 * as `Audit::reasonIsBlank()`'s consumer-side checks, simplified to the
 * ASCII case (`trim() === ''`): a blackout reason is operator-written
 * free text with no reason-mandatory audit rule behind it, so the full
 * Unicode-blank machinery would be unreachable ceremony here.
 *
 * The `(policy_id, date)` unique constraint (migration) is the "one
 * blackout per date" backstop; the policy's `isBlackout()` /
 * `blackoutReasonFor()` helpers are the read API this row exists for.
 */
final class VisitationBlackoutDate extends Model
{
    use HasUuids;

    protected $table = 'visitation_blackout_dates';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'policy_id',
        'date',
        'reason',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'immutable_date',
        ];
    }

    protected static function booted(): void
    {
        self::saving(function (self $blackout): void {
            if (! is_string($blackout->reason) || trim($blackout->reason) === '') {
                throw new InvalidArgumentException('A visitation blackout date requires a non-blank reason.');
            }
        });
    }

    /**
     * True when this row's date covers the given date — the instance
     * predicate mirroring `Cemetery::isPublished()`'s shape; the policy
     * reads its blackout set through the query, not through this.
     */
    public function isBlackout(CarbonImmutable $date): bool
    {
        return $this->date instanceof CarbonImmutable && $this->date->equalTo($date->startOfDay());
    }
}
