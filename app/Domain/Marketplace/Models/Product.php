<?php

declare(strict_types=1);

namespace App\Domain\Marketplace\Models;

use App\Domain\Marketplace\MarketplaceProductCategory;
use App\Domain\Marketplace\ProductCode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Eloquent model for `products` — see the migration
 * (`2026_07_26_180000_create_products_table.php`) for full schema reasoning,
 * including the resolution of the OPEN category-code question
 * (`App\Domain\Marketplace\MarketplaceProductCategory`) and the deliberate
 * absence of vendor/stock/schedule/etc. columns in this batch.
 *
 * One of exactly nine rows, seeded by
 * `2026_07_26_180200_seed_marketplace_products_and_variants.php` from
 * `docs/product/marketplace-catalog.md` — this batch builds no admin
 * create/delete action for the same reason `FaqCategory` builds none: the
 * catalogue is master data owned by a product decision, not free-form
 * admin-editable content, at this stage of the build.
 */
final class Product extends Model
{
    protected $table = 'products';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'code',
        'category',
        'name',
        'description',
        'base_price_idr',
        'price_version',
        'is_active',
        'sort_order',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'base_price_idr' => 'integer',
            'price_version' => 'integer',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        self::saving(function (self $product): void {
            ProductCode::assertKnown($product->code);
            MarketplaceProductCategory::assertKnown($product->category);
        });
    }

    /**
     * @return HasMany<ProductVariant, $this>
     */
    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class, 'product_id')->orderBy('sort_order');
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    public function scopeInCategory(Builder $query, string $category): void
    {
        $query->where('category', $category);
    }

    /**
     * The stable public/admin presentation order — the catalogue's own
     * listed order, mirrored into `sort_order` at seed time.
     */
    public function scopeOrderedForDisplay(Builder $query): void
    {
        $query->orderBy('sort_order');
    }

    public static function findByCode(string $code): ?self
    {
        return self::query()->where('code', $code)->first();
    }

    /**
     * Whether this product's code is expected to carry `product_variants`
     * rows — the three Batu Nisan codes only. See
     * `App\Domain\Marketplace\ProductCode::GRAVESTONE_CODES`'s own doc
     * block.
     */
    public function hasVariantAxes(): bool
    {
        return ProductCode::requiresVariants($this->code);
    }

    public function categoryLabel(): string
    {
        return MarketplaceProductCategory::label($this->category);
    }
}
