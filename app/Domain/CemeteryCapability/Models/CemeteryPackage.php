<?php

declare(strict_types=1);

namespace App\Domain\CemeteryCapability\Models;

use App\Domain\CemeteryCapability\CemeteryPackageAvailabilityStatus;
use App\Domain\CemeteryDirectory\Models\Cemetery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Eloquent model for `cemetery_packages` — see the migration
 * (`2026_07_26_190200_create_cemetery_packages_table.php`) for schema
 * reasoning. Backs requirements.md AC6: "THE SYSTEM SHALL present Makam
 * Tumpang availability explicitly at the location/package/class level."
 *
 * ---------------------------------------------------------------------------
 * Table naming — a documented judgement call
 * ---------------------------------------------------------------------------
 * `design.md`'s Data section lists `cemetery_packages / cemetery_classes`
 * side by side. Read against AC6's own wording ("at the location/package/
 * class level" — three GRANULARITIES of the same concept, not three
 * separate entities) and this batch's brief ("whatever package/class-level
 * availability TABLE" — singular), this batch collapsed the two names into
 * ONE table: `cemetery_packages`, with an optional `class_label` column
 * standing in for the finer "class" granularity when a cemetery breaks a
 * package down further (e.g. "Makam Tumpang" as the package, "Kelas A" as
 * the class). A `class_label = null` row represents package-level
 * availability with no further breakdown. The alternative — two FK-linked
 * tables (`cemetery_packages` owning `cemetery_classes`) — was considered
 * and rejected here as more structure than S4-T1's master-data/seeds scope
 * needs; flagged explicitly in this batch's final report as a real,
 * resolvable-either-way ambiguity, not a silent guess.
 *
 * ---------------------------------------------------------------------------
 * `name` is a free string, NOT a closed-list enum this module owns
 * ---------------------------------------------------------------------------
 * "Makam Baru, Makam Tumpang, Urgent, Pre-Need" is already the canonical,
 * closed *service type* catalogue named in `docs/product/mvp-scope.md` row
 * 3 and `docs/product/product-brief.md` — that catalogue belongs to the
 * `ServiceCatalog`/`Booking` modules (Step 3 "jenis layanan"), NEITHER of
 * which this batch owns or may touch (another agent is building
 * `ServiceCatalog` concurrently in this same Sprint 4 batch). Defining a
 * second, competing closed list here (e.g. `CemeteryPackageType::TUMPANG`)
 * would be exactly the duplication `AGENTS.md` §Documentation forbids
 * ("Do not duplicate canonical catalog data in multiple hand-maintained
 * documents or code locations") and would risk drifting from whatever
 * `ServiceCatalog` actually seeds for the same concept. This table only
 * records, per cemetery, which package/class labels THAT cemetery has
 * configured and their indicative availability — `name` is deliberately a
 * plain operator-supplied string, not this module asserting a canonical
 * service-type list of its own.
 *
 * ---------------------------------------------------------------------------
 * Package/class-level pricing — added 26 Aug 2026
 * ---------------------------------------------------------------------------
 * `price_min`/`price_max`/`price_currency`/`price_source`/`price_effective_at`
 * (`2026_08_26_110000_add_price_fields_to_cemetery_packages_table.php`)
 * mirror `Cemetery`'s own five price columns EXACTLY, not the `Money`/
 * append-only `price_versions` convention `ServiceDefinition` uses — see
 * that migration's own doc block for the reasoning. This is the same
 * indicative, attributed figure `Cemetery::price_min`/`price_max` already
 * is, just resolved at package/class granularity: never a firmer
 * commitment, never charged, never versioned. `App\Livewire\Public\
 * Directory\Support\CemeteryPresenter::packagePriceRange()`/
 * `packagePriceAttribution()` render it with the identical "Perlu
 * konfirmasi" framing the cemetery-level figure already carries.
 */
final class CemeteryPackage extends Model
{
    protected $table = 'cemetery_packages';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'cemetery_id',
        'name',
        'class_label',
        'availability_status',
        'description',
        'sort_order',
        'is_active',
        'price_min',
        'price_max',
        'price_currency',
        'price_source',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_active' => 'boolean',
            'price_min' => 'decimal:2',
            'price_max' => 'decimal:2',
            'price_effective_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        self::saving(function (self $package): void {
            CemeteryPackageAvailabilityStatus::assertKnown($package->availability_status);

            // `price_effective_at` is deliberately NOT in `$fillable` — an
            // admin cannot hand-enter it (see this class's own doc block
            // and the migration's). It is stamped automatically whenever a
            // priced field actually changes, mirroring `PriceVersion.
            // effective_from`'s "recorded at, not hand-entered" discipline
            // without needing that class's append-only/versioning machinery.
            //
            // The `! isDirty('price_effective_at')` guard exists so a
            // caller that DOES explicitly set it — a test fixture backfilling
            // a historical date, or a future admin field — is not silently
            // overwritten by this hook; only the "admin changed a price
            // field and said nothing about the date" path is auto-stamped.
            if ($package->isDirty(['price_min', 'price_max', 'price_currency', 'price_source'])
                && ! $package->isDirty('price_effective_at')) {
                $package->price_effective_at = $package->price_min !== null || $package->price_max !== null
                    ? now()
                    : null;
            }
        });
    }

    /**
     * @return BelongsTo<Cemetery, $this>
     */
    public function cemetery(): BelongsTo
    {
        return $this->belongsTo(Cemetery::class, 'cemetery_id');
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    public function isPackageLevel(): bool
    {
        return $this->class_label === null;
    }
}
