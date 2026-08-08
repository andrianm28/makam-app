<?php

declare(strict_types=1);

namespace App\Livewire\Public\Marketplace;

use App\Domain\Marketplace\MarketplaceCatalogQuery;
use App\Domain\Marketplace\Models\Product;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Component;
use Throwable;

/**
 * `/marketplace/produk/{productCode}` — PUB-021 "Product detail"
 * (screen-inventory.md §A), Sprint 4 S4-T8, spec
 * `funeral-marketplace-and-vendor-portal` AC1-AC3.
 *
 * ---------------------------------------------------------------------------
 * Routed by product CODE, and why the IA's `{productSlug}` is not used
 * ---------------------------------------------------------------------------
 * information-architecture.md §1 writes this route as
 * `/marketplace/produk/{productSlug}`. There is no slug: the `products`
 * table has `code`, `name`, `description`, `vendor_name`, `photo_path`,
 * `base_price_idr`, `price_version`, `is_active`, `sort_order` and nothing
 * else (`2026_07_26_180000_create_products_table.php` plus
 * `2026_07_26_200100_add_dummy_vendor_pricing_and_photo_to_products.php`),
 * and `docs/product/marketplace-catalog.md` names no slug for any of the
 * nine products.
 *
 * Minting one here — even the obvious `Str::slug($product->name)` — would
 * be inventing a public identifier for canonical catalogue data, the same
 * defect `tasks.md`'s category-slug OPEN QUESTION exists to prevent and
 * that AGENTS.md §Documentation forbids ("Do not duplicate canonical
 * catalog data in multiple hand-maintained documents or code locations").
 * So this route carries the catalogue's own `ProductCode` value, which
 * `MarketplaceCatalogQuery::findActiveByCode()`'s own doc block already
 * anticipated when it described itself as "the `/marketplace/produk/{code}`
 * detail lookup". The IA's `{productSlug}` wording is reported as a
 * documentation gap for this batch's owner to resolve; it is not silently
 * "resolved" here by inventing the missing slug.
 *
 * ---------------------------------------------------------------------------
 * 404 discipline
 * ---------------------------------------------------------------------------
 * `findActiveByCode()` returns `null` for BOTH "no such product code" and
 * "the code exists but the product is not active" — deliberately
 * indistinguishable (that method's own doc block). `mount()` calls
 * `abort(404)` in exactly that one case, so a deactivated product 404s the
 * same way a code that never existed does. A distinct "not available"
 * message would leak that the row exists — the same rule `public-faq` AC6
 * applies to unpublished articles and `FaqArticleDetail` implements.
 *
 * ---------------------------------------------------------------------------
 * Variants are DISPLAYED, not SELECTED
 * ---------------------------------------------------------------------------
 * The spec's design.md maps "Variant selector" to `<x-mk.field>`, and
 * "Gravestone configurator" to `<x-mk.field>` plus a preview card. Neither
 * is built here, deliberately: a selector exists to feed a cart, and there
 * is no cart (`tasks.md` "Implement cart and multi-vendor order
 * decomposition ... needs a payment decision not yet made"). A control that
 * accepts a choice and then has nowhere to send it is worse than no
 * control. `tasks.md` likewise still lists "Implement gravestone
 * configurator schema and preview" as **partial** — schema done, "the
 * interactive configurator UI and real preview rendering are not built".
 * That remains true after this batch.
 *
 * Only `GRAVESTONE_*` products carry `product_variants` rows
 * (`ProductCode::GRAVESTONE_CODES`, enforced on every write by
 * `ProductVariant::booted()`), so the view distinguishes three genuinely
 * different situations rather than collapsing them into one empty state:
 * this product family has no variant axes at all; it has them but no rows
 * are configured; it has them and here they are.
 */
final class ProductDetail extends Component
{
    public Product $product;

    public function mount(string $productCode): void
    {
        $product = MarketplaceCatalogQuery::findActiveByCode($productCode);

        if ($product === null) {
            abort(404);
        }

        $this->product = $product;
    }

    public function render(): View
    {
        // §6.5 — the variant panel is secondary; a failure here must not
        // take down the product's own name, price, and support path. Same
        // shape as `HomePage`'s featured-cemeteries panel and `FaqIndex`'s
        // search fallback. A local variable, not a component property,
        // because nothing binds to it.
        $variants = new Collection;
        $variantsUnavailable = false;

        try {
            $variants = MarketplaceCatalogQuery::variantsFor($this->product);
        } catch (Throwable $e) {
            report($e);

            $variantsUnavailable = true;
        }

        return view('livewire.public.marketplace.product-detail', [
            'variants' => $variants,
            'variantsUnavailable' => $variantsUnavailable,
        ])->layout('layouts.app', [
            'title' => $this->product->name.' - Layanan Pemakaman - Makam.co.id',
            'active' => 'layanan',
        ]);
    }
}
