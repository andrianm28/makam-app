<?php

declare(strict_types=1);

namespace App\Livewire\Public\Marketplace;

use App\Domain\Marketplace\MarketplaceCatalogQuery;
use App\Domain\Marketplace\MarketplaceProductCategory;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Url;
use Livewire\Component;
use Throwable;

/**
 * `/marketplace` — PUB-020 "Marketplace landing" (screen-inventory.md §A),
 * Sprint 4 S4-T8, spec `funeral-marketplace-and-vendor-portal` AC1-AC3.
 * Replaces the `App\Livewire\Public\ComingSoon\MarketplaceComingSoon` stub,
 * which that class's own doc block says is "expected to be REPLACED, not
 * extended".
 *
 * BROWSE ONLY. AC3 names the full sequence browse/select -> cart ->
 * checkout -> payment -> vendor processing -> status/evidence; this batch
 * implements the FIRST step of that sequence and nothing after it. Cart,
 * checkout, and the vendor portal need a Tier-3 payment/ledger decision
 * that has not been made (`tasks.md` "Implement cart and multi-vendor order
 * decomposition ... not started; needs a payment decision not yet made"),
 * so there is no add-to-cart affordance anywhere on this page — see
 * `product-detail.blade.php` for how that absence is stated to the user
 * rather than hidden.
 *
 * Every catalogue read goes through `App\Domain\Marketplace\
 * MarketplaceCatalogQuery` — never `Product::query()`. That class was built
 * in S4-T1 specifically so this batch would have one already-correct read
 * entry point (read its own class doc block), and it composes `Product`'s
 * `active()` scope, so an inactive product cannot leak onto a public page
 * through this component. Same discipline `FaqIndex` applies via
 * `FaqPublicQuery`.
 *
 * The page title/H1 is "Layanan Pemakaman" — the label `<x-mk.header>` uses
 * for `/marketplace` in the four-menu contract (information-architecture.md
 * §2, AGENTS.md "Mandatory MVP UX"). AGENTS.md §Marketplace forbids
 * renaming a product label, so this page does not introduce a second name
 * ("Marketplace") for the destination the header already names.
 *
 * ---------------------------------------------------------------------------
 * Category filtering is a QUERY PARAMETER, not the IA's category ROUTE —
 * `/marketplace/kategori/{categorySlug}` stays BLOCKED
 * ---------------------------------------------------------------------------
 * `.kiro/specs/funeral-marketplace-and-vendor-portal/tasks.md` §"⚠️ OPEN
 * QUESTION — category identifiers are undefined" is explicit that
 * `docs/product/marketplace-catalog.md` defines nine product codes and ZERO
 * category codes, that inventing a category slug here would create "a
 * second, competing source of truth", and that until the product owner adds
 * category codes and public slugs to the catalogue, category routing is
 * "BLOCKED, not merely undocumented". That is still true as of this batch:
 * this component does NOT register, imply, or emulate
 * `/marketplace/kategori/{categorySlug}` (information-architecture.md §1),
 * and no route for it is requested.
 *
 * What this component filters by instead is `?kategori=<KEY>`, where `<KEY>`
 * is one of `MarketplaceProductCategory::KNOWN_KEYS` **verbatim**
 * (`FLOWERS` / `GRAVESTONES` / `GRAVE_CARE`). No new identifier is minted:
 * the value in the URL is character-for-character the code-safe key that
 * class already defines and that `products.category` already stores. It is
 * deliberately NOT the Indonesian display text `label()` returns, because a
 * lowercased/hyphenated form of that text ("karangan-bunga") is exactly the
 * public slug the OPEN QUESTION reserves for the product owner. When real
 * category slugs land, this parameter is expected to be replaced by the
 * real route, not kept alongside it.
 *
 * ---------------------------------------------------------------------------
 * An unknown `?kategori=` value does not 404 and does not throw
 * ---------------------------------------------------------------------------
 * `MarketplaceCatalogQuery::activeProductsInCategory()` calls
 * `MarketplaceProductCategory::assertKnown()`, which throws
 * `InvalidArgumentException` — correct for a domain call, wrong to expose
 * on a public page reachable by editing a query string. Unlike `FaqIndex`,
 * which `abort(404)`s an unknown category SLUG, an unknown value here is a
 * bad *parameter* on a URL that genuinely exists, so 404 would be a lie
 * about the page. `render()` validates the key against `isKnown()` first
 * and falls back to the unfiltered catalogue with a §6.3-shaped explanation
 * — the only genuinely reachable validation state on a page with no form.
 *
 * ---------------------------------------------------------------------------
 * This component has NO Livewire actions, and §6.1's loading state is
 * therefore the browser's own, not a skeleton
 * ---------------------------------------------------------------------------
 * Every category chip is a plain `<a href>` — a fresh page request, the same
 * choice `FaqIndex` documents for its own chips. That leaves this page with
 * no async round trip at all: nothing calls back into the component after the
 * initial server-side render, so there is nothing a `wire:loading` skeleton
 * could ever be shown for. An earlier revision of this class carried a
 * `clearCategory()` action purely so the view could target a skeleton at it;
 * it was removed on discovering nothing in the view invoked it, which made
 * both the action and the skeleton unreachable markup. design-system.md §6.1
 * is satisfied here by the browser's own navigation indicator, and this batch
 * reports that as a stated limitation rather than shipping a skeleton that
 * can never render. If a future batch turns category filtering into a real
 * Livewire round trip, the skeleton comes back with it.
 */
final class MarketplaceIndex extends Component
{
    /**
     * One of `MarketplaceProductCategory::KNOWN_KEYS`, or `''` for the
     * unfiltered catalogue. See the class doc block for why this is a query
     * parameter carrying the code-safe key rather than a route segment
     * carrying a public slug.
     */
    #[Url(as: 'kategori', history: true)]
    public string $category = '';

    /**
     * §6.5 "Provider unavailable" — true only when the catalogue read
     * itself throws. The header, the category chips, and the support escape
     * hatch are all rendered from data that does not depend on it, so the
     * page degrades to an honest explanation instead of a blank screen.
     */
    public bool $catalogueUnavailable = false;

    public function render(): View
    {
        $requested = trim($this->category);
        $categoryIsKnown = $requested === '' || MarketplaceProductCategory::isKnown($requested);
        $activeCategory = ($requested !== '' && $categoryIsKnown) ? $requested : null;

        $this->catalogueUnavailable = false;
        $products = new Collection;

        try {
            $products = $activeCategory !== null
                ? MarketplaceCatalogQuery::activeProductsInCategory($activeCategory)
                : MarketplaceCatalogQuery::activeProducts();
        } catch (Throwable $e) {
            report($e);

            $this->catalogueUnavailable = true;
        }

        return view('livewire.public.marketplace.index', [
            'products' => $products,
            'categoryKeys' => MarketplaceProductCategory::KNOWN_KEYS,
            'activeCategory' => $activeCategory,
            'unknownCategoryRequested' => ! $categoryIsKnown,
        ])->layout('layouts.app', [
            'title' => $activeCategory !== null
                ? MarketplaceProductCategory::label($activeCategory).' - Layanan Pemakaman - Makam.co.id'
                : 'Layanan Pemakaman - Makam.co.id',
            // <x-mk.header>'s nav key for /marketplace (see that component's
            // own $items map) — not 'pemesanan'.
            'active' => 'layanan',
        ]);
    }
}
