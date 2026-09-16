<?php

declare(strict_types=1);

namespace App\Domain\ServiceCatalog\Models;

use App\Domain\ServiceCatalog\Concerns\HasVersionedPrice;
use App\Domain\ServiceCatalog\Contracts\Priceable;
use App\Domain\ServiceCatalog\FulfillmentOwner;
use App\Domain\ServiceCatalog\ServiceCategory;
use App\Domain\ServiceCatalog\ServiceCode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Eloquent model for `service_definitions` — see the migration
 * (`2026_07_26_180000_create_service_definitions_table.php`) for schema
 * reasoning. One of exactly 12 rows, seeded by
 * `2026_07_26_180700_seed_service_definitions_from_catalog.php` from
 * `docs/product/service-catalog.md` and never expected to change without a
 * product-catalogue decision first — mirrors `App\Domain\Faq\Models\
 * FaqCategory`'s own "seed-protected master data, not free-form admin
 * content" framing. This batch builds no "create service
 * definition"/"delete service definition" write action for exactly that
 * reason.
 */
final class ServiceDefinition extends Model implements Priceable
{
    use HasVersionedPrice;

    protected $table = 'service_definitions';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'code',
        'name',
        'category',
        'fulfillment_owner',
        'requires_schedule',
        'requires_manual_confirmation',
        'is_active',
        'description',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'requires_schedule' => 'boolean',
            'requires_manual_confirmation' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        self::saving(function (self $definition): void {
            ServiceCode::assertKnown($definition->code);
            ServiceCategory::assertKnown($definition->category);

            if ($definition->fulfillment_owner !== null) {
                FulfillmentOwner::assertKnown($definition->fulfillment_owner);
            }
        });
    }

    /**
     * Package items across every package version that reference this
     * service — includes items belonging to draft AND published versions.
     *
     * @return HasMany<ServicePackageItem, $this>
     */
    public function packageItems(): HasMany
    {
        return $this->hasMany(ServicePackageItem::class, 'service_definition_id');
    }

    /*
     * `priceVersions()` and `currentPriceVersion()` now live in
     * `Concerns\HasVersionedPrice`, shared with `Domain\CemeteryCapability\
     * Models\CemeteryPackage`. The bodies are unchanged; only their home
     * moved, so this class's existing price tests still prove the behaviour.
     *
     * One fact worth keeping from the doc block that used to sit here,
     * because it is about THIS model and not about the mechanism: in practice
     * `currentPriceVersion()` is never `null` for a service in any
     * environment. Since `2026_07_26_220000_seed_service_definition_dummy_
     * operational_data.php` landed, all 12 seeded codes carry a v1 dev-only
     * placeholder price out of the box — that migration's own doc block
     * carries the "not real catalogue pricing" disclaimer. The nullable
     * return type is still correct and still load-bearing, because a service
     * whose price rows were removed reaches it; it is simply not the shipped
     * state.
     */

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    public function scopeOfCategory(Builder $query, string $category): void
    {
        $query->where('category', $category);
    }

    public static function findByCode(string $code): ?self
    {
        return self::query()->where('code', $code)->first();
    }
}
