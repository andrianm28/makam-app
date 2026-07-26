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
     */
    public function packageItems(): HasMany
    {
        return $this->hasMany(ServicePackageItem::class, 'service_definition_id');
    }

    /**
     * This service's own price history — see `Models\PriceVersion`'s own
     * class-level doc block for the append-only/`morphTo` shape.
     */
    public function priceVersions(): MorphMany
    {
        return $this->morphMany(PriceVersion::class, 'priceable');
    }

    /**
     * The one row (if any) with `superseded_at IS NULL` — this service's
     * currently-in-effect price. `null` when no price has ever been
     * recorded for this service yet (the seeded catalogue ships with none —
     * see the seed migration's own doc block).
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
