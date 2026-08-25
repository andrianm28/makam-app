<?php

declare(strict_types=1);

namespace App\Domain\Visitation\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Eloquent model for `visitation_date_capacities` — Task 1 of
 * `docs/superpowers/plans/2026-08-16-p4-memorial-qr-visitation.md`
 * (Lane 1 — Visitation). The atomic capacity ledger: one row per
 * (policy, date) with the running `booked_count`.
 *
 * Deliberately has no model-level guards beyond the schema — every
 * mutation of this table flows through `RequestVisitation`, which
 * serializes on the row lock; a second writer with its own ideas about
 * the count is exactly the oversell this module exists to prevent. The
 * `(policy_id, date)` unique constraint (migration) is the backstop for
 * the lazy-first-insert race, and the only reads are the Action's lock
 * and `VisitationPublicQuery::bookableDates()`'s capacity-left
 * projection.
 */
final class VisitationDateCapacity extends Model
{
    use HasUuids;

    protected $table = 'visitation_date_capacities';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'policy_id',
        'date',
        'booked_count',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'immutable_date',
            'booked_count' => 'integer',
        ];
    }
}
