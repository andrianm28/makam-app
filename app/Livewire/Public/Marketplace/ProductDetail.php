<?php

declare(strict_types=1);

namespace App\Livewire\Public\Marketplace;

use App\Domain\Marketplace\Actions\AddToCart;
use App\Domain\Marketplace\Actions\ReplaceCartWithVendor;
use App\Domain\Marketplace\CartConflict;
use App\Domain\Marketplace\MarketplaceCatalogQuery;
use App\Domain\Marketplace\Models\Cart as CartModel;
use App\Domain\Marketplace\Models\Product;
use App\Domain\Marketplace\Models\VendorListing;
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
 * Add to cart: the first ACTIVE listing is what the button adds
 * ---------------------------------------------------------------------------
 * `vendor_listings` holds the per-vendor offers this page adds to the cart
 * (`AddToCart` requires a `VendorListing`, never a catalogue row). The
 * catalogue is seeded with nine products, one example listing each, so "no
 * active listing" is the deactivated/admin-removed state and must render
 * honestly — the §6.7 pending alert with the customer-service escape hatch.
 * When one or more active listings exist, the page adds the FIRST by
 * creation order (`orderBy('id')`),
 * deterministically, and names that listing's vendor beside the button so
 * "which offer am I adding?" is never a secret. Multiple listings of the
 * same product by different vendors are rare in the MVP and a per-vendor
 * chooser is deliberately NOT built (YAGNI — the cart screen's own
 * one-vendor rule would force a replacement modal the moment a second
 * vendor enters anyway); the deterministic pick is recorded here as the
 * honest MVP reading, to be replaced by a real selector when the catalogue
 * actually carries competing offers.
 *
 * The add always takes the listing-level offer, quantity 1, and NO variant:
 * variants remain DISPLAYED, not SELECTED (see below). The cart line is
 * created with `product_variant_id = null`, which the cart screen already
 * supports; wiring variant choice into the add is future work.
 *
 * The listing read follows §6.5 like the variants panel below it: a failure
 * degrades to the same "no offer yet" rendering as zero listings (the
 * pending alert + support path), because both mean "cannot add to cart
 * right now", rather than taking the whole page down.
 *
 * ---------------------------------------------------------------------------
 * Single-vendor conflict, handled exactly like the cart screen's
 * ---------------------------------------------------------------------------
 * Adding from here goes through the same `AddToCart` (returns
 * `CartConflict`, never throws, never mutates) and the same explicit
 * `ReplaceCartWithVendor` resolution the cart screen uses — the state
 * shape, the modal copy, and the three actions (`addToCart`,
 * `resolveConflictByReplacing`, `dismissConflict`) mirror `Cart`'s by
 * design, so the two surfaces cannot drift. A conflict-free add redirects
 * to `/marketplace/keranjang` (the item is visible there); a conflict
 * opens the modal inline and only an explicit "Ganti keranjang" clears the
 * existing items, exactly as requirement 4 demands.
 *
 * ---------------------------------------------------------------------------
 * Variants are DISPLAYED, not SELECTED
 * ---------------------------------------------------------------------------
 * The spec's design.md maps "Variant selector" to `<x-mk.field>`, and
 * "Gravestone configurator" to `<x-mk.field>` plus a preview card. Neither
 * is built here, deliberately: a selector exists to feed a cart line, and
 * the cart line this page creates is listing-level with no variant — a
 * control that accepts a variant choice and then has nowhere to send it is
 * worse than no control. `tasks.md` likewise still lists "Implement
 * gravestone configurator schema and preview" as **partial** — schema done,
 * "the interactive configurator UI and real preview rendering are not
 * built". That remains true after this batch.
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

    /** True while the single-vendor conflict modal is open. Mirrors `Cart`. */
    public ?bool $conflictOpen = false;

    /**
     * The flattened `CartConflict` for the view. Same shape as `Cart`'s.
     *
     * @var array{existingVendorId: string, existingVendorName: string, incomingVendorId: string, incomingVendorName: string, existingItemCount: int}|null
     */
    public ?array $conflict = null;

    /**
     * The listing behind an open conflict, so `resolveConflictByReplacing`
     * can re-resolve it after the customer decides. Quantity is always 1
     * and the variant always `null` from this page.
     */
    public ?int $pendingListingId = null;

    public function mount(string $productCode): void
    {
        $product = MarketplaceCatalogQuery::findActiveByCode($productCode);

        if ($product === null) {
            abort(404);
        }

        $this->product = $product;
    }

    /**
     * Adds the product's first active listing, quantity 1, to the session
     * cart. A clean add redirects to the cart screen; a single-vendor
     * conflict opens the §3.4 modal inline and changes nothing until the
     * customer explicitly resolves it.
     */
    public function addToCart(): void
    {
        $listing = $this->firstActiveListing();

        if (! $listing instanceof VendorListing) {
            return;
        }

        $result = (new AddToCart)->handle($this->cart(), $listing, 1);

        if ($result instanceof CartConflict) {
            $this->conflict = [
                'existingVendorId' => $result->existingVendorId,
                'existingVendorName' => $result->existingVendorName,
                'incomingVendorId' => $result->incomingVendorId,
                'incomingVendorName' => $result->incomingVendorName,
                'existingItemCount' => $result->existingItemCount,
            ];
            $this->conflictOpen = true;
            $this->pendingListingId = $listing->id;

            return;
        }

        $this->conflict = null;
        $this->conflictOpen = false;
        $this->pendingListingId = null;

        $this->redirectRoute('marketplace.cart');
    }

    /**
     * The explicit resolution the customer chose in the conflict modal:
     * clears the current cart and re-locks it to the incoming listing's
     * vendor, then sends the customer to the cart screen where the
     * replaced cart is visible. The only action that may clear a cart.
     */
    public function resolveConflictByReplacing(): void
    {
        if ($this->pendingListingId === null) {
            return;
        }

        $listing = VendorListing::query()->find($this->pendingListingId);

        if (! $listing instanceof VendorListing) {
            return;
        }

        (new ReplaceCartWithVendor)->handle($this->cart(), $listing, 1, null);

        $this->conflict = null;
        $this->conflictOpen = false;
        $this->pendingListingId = null;

        $this->redirectRoute('marketplace.cart');
    }

    /** Closes the modal WITHOUT touching the cart — the existing items stay. */
    public function dismissConflict(): void
    {
        $this->conflict = null;
        $this->conflictOpen = false;
        $this->pendingListingId = null;
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

        // The offer the add-to-cart button adds: the first active listing
        // for this product, deterministically. §6.5, same discipline as the
        // variants panel: a read failure degrades to the "no offer yet"
        // state (null) instead of taking the page down — the view renders
        // both identically, which is honest because both mean "cannot add
        // to cart right now".
        $listing = $this->firstActiveListing();

        return view('livewire.public.marketplace.product-detail', [
            'variants' => $variants,
            'variantsUnavailable' => $variantsUnavailable,
            'listing' => $listing,
        ])->layout('layouts.app', [
            'title' => $this->product->name.' - Layanan Pemakaman - Makam.co.id',
            'active' => 'layanan',
        ]);
    }

    /**
     * The first active listing of this product, or `null` when there is
     * none (deactivated or removed) or when the read fails (§6.5).
     * Deterministic: creation order, so a product with several offers
     * always adds the same one until a real vendor selector is built.
     */
    private function firstActiveListing(): ?VendorListing
    {
        try {
            return VendorListing::query()
                ->active()
                ->forProduct($this->product->id)
                ->orderBy('id')
                ->first();
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    /** The session's cart, resolved exactly as `Cart`'s own — never from client input. */
    private function cart(): CartModel
    {
        $authenticated = auth()->check();

        return CartModel::query()->firstOrCreate([
            'customer_ref' => $authenticated ? (string) auth()->id() : null,
            'session_ref' => $authenticated ? null : session()->getId(),
        ]);
    }
}
