<?php

declare(strict_types=1);

namespace App\Domain\ServiceCatalog\Models;

use App\Domain\ServiceCatalog\FulfillmentOwner;
use App\Domain\ServiceCatalog\ServiceCategory;
use App\Domain\ServiceCatalog\ServiceCode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

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
final class ServiceDefinition extends Model
{
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

    /**
     * This service's own price history — see `Models\PriceVersion`'s own
     * class-level doc block for the append-only/`morphTo` shape.
     *
     * @return MorphMany<PriceVersion, $this>
     */
    public function priceVersions(): MorphMany
    {
        return $this->morphMany(PriceVersion::class, 'priceable');
    }

    /**
     * The one row (if any) with `superseded_at IS NULL` — this service's
     * currently-in-effect price.
     *
     * In practice this is never `null` in any environment: since
     * `2026_07_26_220000_seed_service_definition_dummy_operational_data.php`
     * landed, every one of the 12 seeded codes carries a v1 dev-only
     * placeholder price out of the box (that migration's own doc block has
     * the "not real catalogue pricing" disclaimer). The nullable return type
     * is still correct and still load-bearing — a caller must handle the
     * case, which is reachable for a service whose price rows were removed —
     * but it is not the shipped state. (This doc block previously read "the
     * seeded catalogue ships with none", which stopped being true the day
     * that migration landed; corrected 09 Aug 2026 by the ServiceCatalog
     * Superpowers retrofit, F9.)
     */
    public function currentPriceVersion(): ?PriceVersion
    {
        return $this->priceVersions()->whereNull('superseded_at')->orderByDesc('version_number')->first();
    }

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
