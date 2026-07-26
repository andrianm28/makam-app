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
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        self::saving(function (self $package): void {
            CemeteryPackageAvailabilityStatus::assertKnown($package->availability_status);
        });
    }

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
