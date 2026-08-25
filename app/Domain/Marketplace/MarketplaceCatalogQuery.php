<?php

declare(strict_types=1);

namespace App\Domain\Marketplace;

use App\Domain\Marketplace\Models\Product;
use App\Domain\Marketplace\Models\ProductVariant;
use Illuminate\Database\Eloquent\Collection;

/**
 * The recommended read entry point for the marketplace product catalogue —
 * a thin wrapper around `Product`'s own scopes, the same role
 * `App\Domain\Faq\FaqPublicQuery` plays for `FaqArticle` (see that class's
 * own doc block for the full rationale this one follows).
 *
 * This batch (S4-T1) builds no Livewire view or Filament resource — that is
 * `funeral-marketplace-and-vendor-portal` AC1-AC3 / sprint task S4-T8. This
 * class exists so THAT later batch (and any test in the meantime) has a
 * single, already-correct place to read active catalogue data from, instead
 * of every call site writing its own `Product::active()->...` query and
 * risking an inactive/out-of-order result.
 */
final class MarketplaceCatalogQuery
{
    /**
     * Every active product, in catalogue display order — S4-T8's browse
     * listing.
     *
     * @return Collection<int, Product>
     */
    public static function activeProducts(): Collection
    {
        return Product::active()->orderedForDisplay()->get();
    }

    /**
     * Active products within one `MarketplaceProductCategory` key, in
     * display order.
     *
     * @return Collection<int, Product>
     */
    public static function activeProductsInCategory(string $category): Collection
    {
        MarketplaceProductCategory::assertKnown($category);

        return Product::active()->inCategory($category)->orderedForDisplay()->get();
    }

    /**
     * The `/marketplace/produk/{code}` detail lookup — `null` for a code
     * that either does not exist or is not active. Never throws for an
     * unknown code (a detail route should render "not found," not a 500).
     */
    public static function findActiveByCode(string $code): ?Product
    {
        if (! ProductCode::isKnown($code)) {
            return null;
        }

        return Product::active()->where('code', $code)->first();
    }

    /**
     * A product's variant rows in display order — always empty for a
     * `FLOWER_*`/`GRAVE_CARE_*` product (see `ProductVariant`'s own
     * class-level doc block).
     *
     * @return Collection<int, ProductVariant>
     */
    public static function variantsFor(Product $product): Collection
    {
        return $product->variants()->orderedForDisplay()->get();
    }
}
