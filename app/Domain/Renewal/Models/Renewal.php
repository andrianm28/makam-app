<?php

declare(strict_types=1);

namespace App\Domain\Renewal\Models;

use App\Domain\GraveRegistry\Models\GraveRecord;
use App\Domain\Renewal\RenewalSource;
use App\Domain\Renewal\RenewalStatus;
use Database\Factories\RenewalFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
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
 * that migration's own doc block. Both row-creation paths now exist —
 * `OpenRenewal` (ONLINE) and `MarkExternalRenewal` (EXTERNAL) — and an
 * already-open row of either source can additionally be settled off-platform
 * by `MarkRenewalPaidExternally`, which records its own marking row (see
 * `externalMarking()` below).
 *
 * `target_due_period` is a `date`, not a string — it holds the grave
 * record's `due_date` AT THE MOMENT this renewal was opened, so it is
 * comparable and unambiguous ("2027" and "2027-01" cannot both mean the
 * same period). See the migration for the full reasoning.
 *
 * `HasFactory` added fix round 1 (ride-along, not a review finding) —
 * `docs/superpowers/plans/2026-08-12-platform-renewal-completion.md`'s later
 * tasks (5, 6, 7) call `Renewal::factory()->create(...)` in their own
 * pre-written test code, so shipping this model without it would leave
 * every later task blocked on re-opening this file. Same `newFactory()`
 * override reasoning as `App\Domain\GraveRegistry\Models\GraveRecord`'s own
 * doc block: the default resolver only strips a leading `App\Models\`, so it
 * cannot find `Database\Factories\RenewalFactory` for a model namespaced
 * under `App\Domain\Renewal\Models`.
 */
final class Renewal extends Model
{
    /** @use HasFactory<RenewalFactory> */
    use HasFactory, HasUuids;

    protected $table = 'renewals';

    /**
     * `HasFactory`'s default resolver strips a leading `App\Models\` and
     * nothing else, so it cannot find a factory for a model namespaced
     * under `App\Domain\...\Models` — every domain model in this codebase.
     * Explicit override, the same reasoning `App\Domain\GraveRegistry\
     * Models\GraveRecord` documents for its own.
     */
    protected static function newFactory(): RenewalFactory
    {
        return RenewalFactory::new();
    }

    /**
     * @var list<string>
     */
    protected $fillable = [
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
     * The AC10 evidence row, written by one of two actions: `MarkExternalRenewal`
     * creates an EXTERNAL-sourced renewal together with its marking, and
     * `MarkRenewalPaidExternally` settles an already-open renewal of ANY
     * source (including ONLINE) with money that changed hands off-platform,
     * recording its marking here. An ONLINE row therefore CAN carry a
     * marking — a `null` means "no off-platform settlement was recorded",
     * not "an ONLINE row never has one".
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
