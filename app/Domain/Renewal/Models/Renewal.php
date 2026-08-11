<?php

declare(strict_types=1);

namespace App\Domain\Renewal\Models;

use App\Domain\GraveRegistry\Models\GraveRecord;
use App\Domain\Renewal\RenewalSource;
use App\Domain\Renewal\RenewalStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Eloquent model for `renewals` —
 * `.kiro/specs/renewal-and-grave-registry/design.md`'s Data section, and the
 * AC11 duplicate-period guard's subject table
 * (`2026_08_12_100000_create_renewals_table.php`).
 *
 * ---------------------------------------------------------------------------
 * One table, two write paths — this is what makes the guard work
 * ---------------------------------------------------------------------------
 * A row here is created either by a family completing the public renewal
 * journey (`source = RenewalSource::ONLINE`) or by an admin recording a
 * payment settled outside the platform (`source = RenewalSource::EXTERNAL`,
 * AC10). Both are ordinary rows in this one table, so
 * `renewals_grave_period_unique` on `(grave_record_id, target_due_period)`
 * covers both without a second uniqueness mechanism to keep in sync — see
 * that migration's own doc block. Neither write path (`OpenRenewal`,
 * `MarkExternalRenewal`) exists yet; this task only creates the schema and
 * the model those later tasks will use.
 *
 * `target_due_period` is a `date`, not a string — it holds the grave
 * record's `due_date` AT THE MOMENT this renewal was opened, so it is
 * comparable and unambiguous ("2027" and "2027-01" cannot both mean the
 * same period). See the migration for the full reasoning.
 */
final class Renewal extends Model
{
    use HasUuids;

    protected $table = 'renewals';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'id',
        'grave_record_id',
        'target_due_period',
        'reference',
        'status',
        'source',
        'settled_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'target_due_period' => 'immutable_date',
            'settled_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        self::saving(function (self $renewal): void {
            RenewalStatus::assertKnown((string) $renewal->status);
            RenewalSource::assertKnown((string) $renewal->source);
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
     * A renewal may accumulate more than one quote over its lifetime (a
     * tariff re-quote, for instance) — `RenewalQuote`'s own doc block owns
     * which one is authoritative at read time. This relation is deliberately
     * `HasMany`, not `HasOne`.
     *
     * @return HasMany<RenewalQuote, $this>
     */
    public function quotes(): HasMany
    {
        return $this->hasMany(RenewalQuote::class, 'renewal_id');
    }

    /**
     * Populated only when `source = RenewalSource::EXTERNAL` (AC10). A
     * `RenewalSource::ONLINE` row has no external marking and this relation
     * resolves to `null` for it — that is expected, not an error state.
     *
     * @return HasOne<RenewalExternalMarking, $this>
     */
    public function externalMarking(): HasOne
    {
        return $this->hasOne(RenewalExternalMarking::class, 'renewal_id');
    }

    public function isSettled(): bool
    {
        return $this->status === RenewalStatus::DIBAYAR;
    }
}
