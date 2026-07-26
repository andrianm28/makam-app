<?php

declare(strict_types=1);

namespace App\Domain\ServiceCatalog\Models;

use App\Domain\ServiceCatalog\Exceptions\PublishedServicePackageVersionIsImmutableException;
use App\Domain\ServiceCatalog\FulfillmentOwner;
use App\Domain\ServiceCatalog\ServicePackageItemType;
use App\Domain\ServiceCatalog\ServicePackageVersionStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Eloquent model for `service_package_items` — see the migration
 * (`2026_07_26_180300_create_service_package_items_table.php`) for schema
 * reasoning.
 *
 * ---------------------------------------------------------------------------
 * THE AC2 GUARANTEE (item side) — see `Models\ServicePackageVersion`'s own
 * class-level doc block for the version-side half
 * ---------------------------------------------------------------------------
 * An item's own row has no `status` column — its editability is entirely
 * derived from its OWNING version. `booted()` below queries that version's
 * current `status` fresh on every save/delete (never trusts an
 * already-loaded, possibly-stale `version` relation) and refuses the
 * operation if it is `published`. NEVER write to this table via anything
 * other than `Actions\DefineServicePackage` (draft authoring, version
 * always freshly created as `draft`) or `Actions\
 * ReviseServicePackageVersion` (copying into a fresh draft) — both only
 * ever operate on a version they themselves just confirmed is `draft`.
 */
final class ServicePackageItem extends Model
{
    protected $table = 'service_package_items';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'service_package_version_id',
        'service_definition_id',
        'item_type',
        'quantity',
        'unit',
        'fulfillment_owner',
        'service_area',
        'requires_schedule_window',
        'evidence_required',
        'notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'requires_schedule_window' => 'boolean',
            'evidence_required' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        self::saving(function (self $item): void {
            ServicePackageItemType::assertKnown($item->item_type);
            FulfillmentOwner::assertKnown($item->fulfillment_owner);

            self::assertOwningVersionIsEditable((int) $item->service_package_version_id);
        });

        self::deleting(function (self $item): void {
            self::assertOwningVersionIsEditable((int) $item->service_package_version_id);
        });
    }

    private static function assertOwningVersionIsEditable(int $versionId): void
    {
        $status = ServicePackageVersion::query()->whereKey($versionId)->value('status');

        if ($status === ServicePackageVersionStatus::PUBLISHED) {
            throw PublishedServicePackageVersionIsImmutableException::forItemOfVersion($versionId);
        }
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(ServicePackageVersion::class, 'service_package_version_id');
    }

    public function serviceDefinition(): BelongsTo
    {
        return $this->belongsTo(ServiceDefinition::class, 'service_definition_id');
    }

    /**
     * Configured substitute(s) for this item — see
     * `Models\SubstitutionPolicy`'s own class-level doc block.
     */
    public function substitutionPolicies(): HasMany
    {
        return $this->hasMany(SubstitutionPolicy::class, 'service_package_item_id');
    }

    /**
     * What evidence this item needs to be marked fulfilled — see
     * `Models\EvidenceRequirement`'s own class-level doc block.
     */
    public function evidenceRequirements(): HasMany
    {
        return $this->hasMany(EvidenceRequirement::class, 'service_package_item_id');
    }

    public function scopeOfType(Builder $query, string $itemType): void
    {
        $query->where('item_type', $itemType);
    }
}
