<?php

declare(strict_types=1);

namespace App\Domain\GraveRegistry\Models;

use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Domain\GraveRegistry\GraveNameNormalizer;
use App\Domain\GraveRegistry\GraveRecordAccessMode;
use App\Domain\GraveRegistry\GraveRecordSource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Eloquent model for `grave_records` —
 * `.kiro/specs/renewal-and-grave-registry/design.md`'s Data section names
 * this table and its columns. Backs requirements.md AC3 (fuzzy search by
 * deceased name, block, and death date), AC12 (the record's own fields) and
 * AC14 (open/limited/closed access modes).
 *
 * Sprint 4 task S4-T7 — the public renewal skeleton. The rest of this
 * spec's tables (`grave_import_batches`, `grave_import_rows`,
 * `grave_import_errors`, `renewals`, `renewal_quotes`,
 * `renewal_external_markings`, `reminder_deliveries`) are NOT created by
 * this batch; `docs/planning/sprint-plan.md` §9 puts the 10k import, the
 * tariff/payment path, the duplicate-period guard, and the reminder
 * scheduler in Sprint 13.
 *
 * ---------------------------------------------------------------------------
 * No public read goes through this model directly
 * ---------------------------------------------------------------------------
 * `design.md`'s Search section: "Search query must apply access policy
 * before returning fields." A caller holding a `GraveRecord` instance holds
 * every column, including the ones AC14 may forbid showing. The public
 * projection therefore lives in `App\Domain\GraveRegistry\
 * GraveRegistryPublicQuery`, which returns `GraveRecordProjection` value
 * objects — never models — and every public surface reads through it. The
 * scopes below exist to COMPOSE that query, not to be called from a
 * Livewire component or a Blade view (`makam-livewire-page`: "Never call an
 * Eloquent model from a component or a Blade view").
 *
 * ---------------------------------------------------------------------------
 * `id` is a UUID
 * ---------------------------------------------------------------------------
 * Two reasons, in this order. First, privacy: a sequential integer key on a
 * registry of deceased persons is itself a disclosure — it makes the
 * registry's size and growth rate readable from any single id, and makes
 * neighbouring records enumerable. AC14's whole point is that not every row
 * is publicly readable, and an enumerable key undercuts that. Second,
 * consistency: `cemeteries` (this table's parent) is already UUID-keyed,
 * and `docs/contracts/openapi.yaml` fixes `format: uuid` for every
 * domain-facing resource id it names, including `renewalId` on
 * `/renewals/{renewalId}/payment-session` — the closest neighbour this spec
 * has in that contract. `/grave-records/search` takes no id parameter at
 * all, so nothing in the contract had to be overridden to choose this.
 */
final class GraveRecord extends Model
{
    use HasUuids;

    protected $table = 'grave_records';

    /**
     * Two columns of this table are deliberately absent from this list.
     *
     * `deceased_name_normalized` is fully derived by the `saving` hook
     * below, and the migration says so in its own words ("Never set by a
     * caller, never edited by hand"). Leaving it mass-assignable advertised
     * an input this model does not have — whatever a caller passed was
     * overwritten before it reached the database.
     *
     * `heir_contact_reference` has no write path anywhere today
     * (`app/Domain/GraveRegistry/Actions/` is empty and no seeded row
     * populates it), and `AGENTS.md` §Authorization and files puts heir
     * contact in the same protected class as KTP/KK/death documents.
     * Whatever eventually writes it should do so deliberately, not by
     * happening to be in an array a bulk import splats a CSV row into.
     * `GraveRecordProjection` has no property for it under ANY access mode,
     * so nothing can read it back out either.
     *
     * @var list<string>
     */
    protected $fillable = [
        'id',
        'cemetery_id',
        'deceased_name',
        'block',
        'death_date',
        'due_date',
        'access_mode',
        'source',
        'source_updated_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'death_date' => 'immutable_date',
            'due_date' => 'immutable_date',
            'source_updated_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        self::saving(function (self $record): void {
            // Derived, never caller-supplied: the stored normalized form
            // and the form `GraveRegistryPublicQuery` searches against MUST
            // come from the same function or trigram similarity silently
            // degrades. See GraveNameNormalizer's own doc block.
            $record->deceased_name_normalized = GraveNameNormalizer::normalize(
                (string) $record->deceased_name
            );

            GraveRecordAccessMode::assertKnown((string) $record->access_mode);
            GraveRecordSource::assertKnown((string) $record->source);
        });
    }

    /**
     * @return BelongsTo<Cemetery, $this>
     */
    public function cemetery(): BelongsTo
    {
        return $this->belongsTo(Cemetery::class, 'cemetery_id');
    }

    /**
     * AC3's composite filter — "fuzzy search by deceased name, block, and
     * death date" — narrowed to one cemetery. The renewal journey always
     * arrives here having already chosen a city and a TPU/TPS (AC1 steps 1
     * and 2), so an unscoped registry-wide search is not a case this
     * journey produces and is deliberately not offered as a scope.
     */
    public function scopeInCemetery(Builder $query, string $cemeteryId): void
    {
        $query->where('cemetery_id', $cemeteryId);
    }

    public function scopeInBlock(Builder $query, string $block): void
    {
        $query->where('block', $block);
    }

    public function scopeDiedOn(Builder $query, string $deathDate): void
    {
        $query->whereDate('death_date', $deathDate);
    }

    /**
     * `true` when AC14's configured mode withholds at least one field from
     * the public projection — i.e. when this row must be reported through
     * design-system.md §6.2's *privacy-limited* state rather than as an
     * ordinary result row. Never a reason to omit the row: see
     * `GraveRecordAccessMode`'s own doc block.
     */
    public function isAccessRestricted(): bool
    {
        return GraveRecordAccessMode::isRestricted((string) $this->access_mode);
    }

    /**
     * `true` when this row is fictional example data rather than a real
     * registry record. Public surfaces rendering seeded rows must be able
     * to say so — see `GraveRecordSource::CONTOH`.
     */
    public function isExampleData(): bool
    {
        return $this->source === GraveRecordSource::CONTOH;
    }
}
