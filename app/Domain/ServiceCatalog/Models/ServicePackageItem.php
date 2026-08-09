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

            self::assertBothOwningVersionsAreEditable($item);
        });

        self::deleting(function (self $item): void {
            self::assertBothOwningVersionsAreEditable($item);
        });
    }

    /**
     * Checks the INCOMING owning version id and — for an already-persisted
     * row — the ORIGINAL one too.
     *
     * Checking only the incoming id is one-directional: it blocks moving an
     * item INTO a published version but permits the destructive direction,
     * moving one OUT of a published version
     * (`$item->service_package_version_id = $draftId; $item->save();`, or
     * the same in-memory re-point followed by `delete()`). Either strips a
     * line item out of a frozen version, which is exactly the modification
     * AC2 forbids.
     */
    private static function assertBothOwningVersionsAreEditable(self $item): void
    {
        self::assertOwningVersionIsEditable((int) $item->service_package_version_id);

        if ($item->exists) {
            self::assertOwningVersionIsEditable((int) $item->getOriginal('service_package_version_id'));
        }
    }

    private static function assertOwningVersionIsEditable(int $versionId): void
    {
        $status = ServicePackageVersion::query()->whereKey($versionId)->value('status');

        if ($status === ServicePackageVersionStatus::PUBLISHED) {
            throw PublishedServicePackageVersionIsImmutableException::forItemOfVersion($versionId);
        }
    }

    /**
     * The same AC2 check, one level down, for this item's own child rows
     * (`Models\SubstitutionPolicy`, `Models\EvidenceRequirement`) — they
     * carry no `status` and no version id of their own, so their editability
     * is derived through their owning ITEM's owning VERSION. Resolved fresh
     * on every call for the same reason this class never trusts a loaded
     * `version` relation: it may be stale.
     *
     * An `$itemId` that resolves to no row is deliberately permitted — that
     * is an FK violation for the database to report, not an AC2 breach.
     */
    public static function assertOwningVersionOfItemIsEditable(int|string|null $itemId, string $childTable): void
    {
        $versionId = self::query()->whereKey($itemId)->value('service_package_version_id');

        if ($versionId === null) {
            return;
        }

        $status = ServicePackageVersion::query()->whereKey($versionId)->value('status');

        if ($status === ServicePackageVersionStatus::PUBLISHED) {
            throw PublishedServicePackageVersionIsImmutableException::forChildOfItem($itemId, $childTable);
        }
    }

    /**
     * @return BelongsTo<ServicePackageVersion, $this>
     */
    public function version(): BelongsTo
    {
        return $this->belongsTo(ServicePackageVersion::class, 'service_package_version_id');
    }

    /**
     * @return BelongsTo<ServiceDefinition, $this>
     */
    public function serviceDefinition(): BelongsTo
    {
        return $this->belongsTo(ServiceDefinition::class, 'service_definition_id');
    }

    /**
     * Configured substitute(s) for this item — see
     * `Models\SubstitutionPolicy`'s own class-level doc block.
     *
     * @return HasMany<SubstitutionPolicy, $this>
     */
    public function substitutionPolicies(): HasMany
    {
        return $this->hasMany(SubstitutionPolicy::class, 'service_package_item_id');
    }

    /**
     * What evidence this item needs to be marked fulfilled — see
     * `Models\EvidenceRequirement`'s own class-level doc block.
     *
     * @return HasMany<EvidenceRequirement, $this>
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
