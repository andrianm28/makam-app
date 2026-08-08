<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Public\Marketplace;

use App\Domain\Marketplace\MarketplaceProductCategory;
use App\Domain\Marketplace\Models\Product;
use App\Domain\Marketplace\ProductCode;
use App\Livewire\Public\Marketplace\MarketplaceIndex;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

/**
 * `/marketplace` — PUB-020, Sprint 4 S4-T8, spec
 * `funeral-marketplace-and-vendor-portal` AC1-AC3. Exercises the REAL
 * seeded catalogue (`2026_07_26_180200_seed_marketplace_products_and_
 * variants.php` plus `2026_07_26_200100_add_dummy_vendor_pricing_and_
 * photo_to_products.php`) — no factories, matching
 * `FaqIndexRouteTest`'s discipline and `makam-testing`'s rule that domain
 * rows come from seed migrations and real Actions.
 *
 * Product codes are referenced through `ProductCode`, never retyped —
 * `.kiro/specs/funeral-marketplace-and-vendor-portal/tasks.md`: *"Do not
 * restate the product list in a Livewire component, a Blade view, a Filament
 * Resource, a validation rule, or a test fixture."* Assertions match on the
 * detail-route URL each code produces rather than on product display names,
 * which is both enum-derived and unambiguous: several seeded names are
 * substrings of each other ("Bulanan" inside "3 Bulan"/"6 Bulan" prose,
 * "Granit" inside the variant material "Granit Hitam"), so a name-based
 * `assertDontSee` would produce false failures.
 *
 * ---------------------------------------------------------------------------
 * DEPENDENCY — these tests cannot pass until `routes/web.php` registers the
 * two routes S4-T8 requested
 * ---------------------------------------------------------------------------
 * S4-T8 does not own `routes/web.php`. Every `$this->get(...)` below, and
 * every `route()` call the views make, needs:
 *
 *   Route::get('/marketplace', MarketplaceIndex::class)->name('marketplace.index');
 *   Route::get('/marketplace/produk/{productCode}', ProductDetail::class)->name('marketplace.product');
 *
 * with `marketplace.index` REPLACING the existing `MarketplaceComingSoon`
 * stub registration, not sitting alongside it. Until that lands these tests
 * fail at the routing layer, which is the correct and visible failure mode —
 * they are not skipped or weakened to hide it.
 */
final class MarketplaceIndexRouteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Real HTTP below renders layouts/app.blade.php, which contains
        // `@vite(...)`; CI's `php` job has no frontend build. Same
        // requirement as FaqIndexRouteTest.
        $this->withoutVite();
    }

    public function test_marketplace_index_returns_ok_and_links_every_seeded_active_product(): void
    {
        $response = $this->get('/marketplace');

        $response->assertOk();

        foreach (ProductCode::KNOWN_CODES as $code) {
            $response->assertSee("/marketplace/produk/{$code}");
        }
    }

    public function test_marketplace_index_shows_the_menu_label_the_header_uses_for_this_route(): void
    {
        // AGENTS.md: "Never rename, reorder, or hide a product label, route,
        // menu item, or booking step." /marketplace is "Layanan Pemakaman"
        // in the four-menu contract, so the landing page must not introduce
        // a second name for it.
        $response = $this->get('/marketplace');

        $response->assertOk();
        $response->assertSee('Layanan Pemakaman');
    }

    public function test_category_filter_narrows_the_list_to_that_category_only(): void
    {
        $response = $this->get('/marketplace?kategori='.MarketplaceProductCategory::FLOWERS);

        $response->assertOk();
        $response->assertSee('/marketplace/produk/'.ProductCode::FLOWER_BOARD);
        $response->assertSee('/marketplace/produk/'.ProductCode::FLOWER_PETAL_PACKAGE);
        $response->assertDontSee('/marketplace/produk/'.ProductCode::GRAVESTONE_GRANITE);
        $response->assertDontSee('/marketplace/produk/'.ProductCode::GRAVE_CARE_ANNUAL);
    }

    public function test_every_known_category_key_filters_to_exactly_its_own_products(): void
    {
        foreach (MarketplaceProductCategory::KNOWN_KEYS as $key) {
            $response = $this->get('/marketplace?kategori='.$key);
            $response->assertOk();

            foreach (ProductCode::KNOWN_CODES as $code) {
                if (ProductCode::categoryFor($code) === $key) {
                    $response->assertSee("/marketplace/produk/{$code}");
                } else {
                    $response->assertDontSee("/marketplace/produk/{$code}");
                }
            }
        }
    }

    public function test_an_unknown_category_parameter_explains_itself_and_falls_back_to_the_full_catalogue(): void
    {
        // Deliberately NOT a 404: `/marketplace` exists, only the query
        // parameter is wrong. See MarketplaceIndex's own doc block for why
        // this differs from FaqIndex's unknown-slug abort(404).
        $response = $this->get('/marketplace?kategori=KATEGORI_YANG_TIDAK_ADA');

        $response->assertOk();
        $response->assertSee('Kategori tidak dikenali');

        foreach (ProductCode::KNOWN_CODES as $code) {
            $response->assertSee("/marketplace/produk/{$code}");
        }
    }

    public function test_an_unknown_category_parameter_never_surfaces_the_domain_exception_message(): void
    {
        // MarketplaceProductCategory::assertKnown() throws an
        // InvalidArgumentException whose message enumerates every known key.
        // render() must validate before calling the query, so that message
        // can never reach a public response.
        $response = $this->get('/marketplace?kategori=KATEGORI_YANG_TIDAK_ADA');

        $response->assertOk();
        $response->assertDontSee('Unknown marketplace product category');
    }

    public function test_the_category_slug_route_is_still_blocked_and_deliberately_unregistered(): void
    {
        // This test asserts a KNOWN GAP still exists, the genre
        // `AuditEventAppendOnlyTest` established. tasks.md §"⚠️ OPEN
        // QUESTION" says `/marketplace/kategori/{categorySlug}` is BLOCKED
        // until the product owner adds category codes and public slugs to
        // marketplace-catalog.md. S4-T8 did not invent them, so no such
        // route exists and an Indonesian category slug 404s. When the
        // product owner resolves the open question and the real route
        // lands, this test SHOULD fail — that failure is the signal to
        // delete it, not a regression.
        $this->get('/marketplace/kategori/karangan-bunga')->assertNotFound();
        $this->get('/marketplace/kategori/batu-nisan')->assertNotFound();
        $this->get('/marketplace/kategori/perawatan-makam')->assertNotFound();
    }

    public function test_empty_category_state_renders_when_every_product_in_a_category_is_deactivated(): void
    {
        // A realistic "the vendor pulled its whole range" scenario, and the
        // only way to reach §6.2's empty state against the real seed, which
        // ships all nine products active. No domain Action exists to
        // deactivate a product (app/Domain/Marketplace/Actions is empty as
        // of S4-T1), so this saves through the model, which still runs
        // Product::booted()'s code/category assertions.
        Product::query()
            ->where('category', MarketplaceProductCategory::FLOWERS)
            ->get()
            ->each(function (Product $product): void {
                $product->is_active = false;
                $product->save();
            });

        $response = $this->get('/marketplace?kategori='.MarketplaceProductCategory::FLOWERS);

        $response->assertOk();
        $response->assertSee('Belum ada produk di kategori ini.');
        $response->assertSee('Lihat kategori lain');
        $response->assertSee('Hubungi Customer Service');
    }

    public function test_a_deactivated_product_disappears_from_the_unfiltered_index(): void
    {
        // The `active()` scope composed inside MarketplaceCatalogQuery is
        // the only thing standing between an inactive row and a public
        // page — asserted directly rather than assumed.
        $product = Product::findByCode(ProductCode::GRAVESTONE_MARBLE);
        $this->assertNotNull($product);

        $product->is_active = false;
        $product->save();

        $response = $this->get('/marketplace');

        $response->assertOk();
        $response->assertDontSee('/marketplace/produk/'.ProductCode::GRAVESTONE_MARBLE);
        // The rest of the catalogue is unaffected.
        $response->assertSee('/marketplace/produk/'.ProductCode::GRAVESTONE_GRANITE);
    }

    public function test_the_active_filter_chip_carries_a_non_colour_tick_not_just_a_colour_change(): void
    {
        // WCAG 1.4.1. The active and inactive chips render an otherwise
        // IDENTICAL recipe (`inline-flex items-center gap-1 rounded-sm
        // border px-2 py-0.5 text-xs font-medium`) and differ only in hue,
        // so without this tick a sighted user who cannot distinguish the
        // two hues has no cue inside the chip group at all. Asserting the
        // real Heroicons v2 `check` path data rather than a class name
        // means deleting the icon as "decoration" fails here instead of
        // regressing silently — a class-name assertion would survive the
        // icon being swapped for an empty span.
        $checkPath = 'm4.5 12.75 6 6 9-13.5';

        // Default view: "Semua Kategori" is the active chip.
        $this->get('/marketplace')->assertOk()->assertSee($checkPath, false);

        // And with a real category filter applied.
        $this->get('/marketplace?kategori='.MarketplaceProductCategory::GRAVESTONES)
            ->assertOk()
            ->assertSee($checkPath, false);
    }

    public function test_exactly_one_filter_chip_is_marked_current_at_a_time(): void
    {
        // The tick's companion cue for assistive tech. Scoped to the chip
        // <nav> rather than counted page-wide: <x-mk.header> emits its own
        // aria-current="page" for the active menu item in BOTH its mobile
        // and desktop bars, and this page passes active='layanan', so a
        // page-wide count is 3, not 1. Same instinct as the FAQ tests
        // scoping ordering assertions to <body> so <title> cannot produce a
        // false match. (Deliberately no line-number citation for the header:
        // an unrelated comment-only edit to that shared file on 8 Aug 2026
        // shifted the lines by 8, which is exactly how such citations rot.)
        foreach (['/marketplace', '/marketplace?kategori='.MarketplaceProductCategory::FLOWERS] as $url) {
            $body = $this->get($url)->assertOk()->getContent();

            $start = strpos($body, 'aria-label="Filter kategori produk"');
            $this->assertNotFalse($start, "Filter chip nav not found on [{$url}].");

            $end = strpos($body, '</nav>', $start);
            $this->assertNotFalse($end, "Filter chip nav is unterminated on [{$url}].");

            $chipNav = substr($body, $start, $end - $start);

            $this->assertSame(
                1,
                substr_count($chipNav, 'aria-current="page"'),
                "Expected exactly one current filter chip on [{$url}]."
            );
        }
    }

    public function test_the_support_escape_hatch_is_present_on_the_landing_page(): void
    {
        // design-system.md §6.10 — required on every screen.
        $response = $this->get('/marketplace');

        $response->assertOk();
        $response->assertSee('/bantuan');
        $response->assertSee('Hubungi Customer Service');
    }

    public function test_the_landing_page_offers_no_cart_or_checkout_affordance(): void
    {
        // Browse only. Cart/checkout are Sprint 11-12 and need a Tier-3
        // payment decision (tasks.md); nothing here may imply they exist.
        $response = $this->get('/marketplace');

        $response->assertOk();
        $response->assertDontSee('/marketplace/keranjang');
        $response->assertDontSee('/marketplace/checkout');
        $response->assertDontSee('Tambah ke Keranjang');
    }

    public function test_browsing_is_read_only_and_repeated_renders_never_mutate_the_catalogue(): void
    {
        // §6.6 duplicate/retry-safe — a plain read has no state-mutating
        // side effect, so repetition is naturally safe. Asserted rather
        // than assumed, the same way FaqIndexRouteTest does it.
        $countBefore = Product::query()->count();

        $test = Livewire::test(MarketplaceIndex::class);
        $test->set('category', MarketplaceProductCategory::GRAVESTONES);
        $test->set('category', MarketplaceProductCategory::GRAVE_CARE);
        $test->set('category', '');

        $this->assertSame($countBefore, Product::query()->count());
    }

    public function test_the_component_exposes_no_livewire_actions_to_call(): void
    {
        // Asserts a deliberate design decision, not an accident: every
        // category chip is a plain link, so this component has no public
        // action methods and therefore no async round trip to skeleton.
        // See MarketplaceIndex's own doc block on §6.1. If a future batch
        // adds a real Livewire interaction, this test SHOULD fail — that is
        // the prompt to bring the loading state back with it.
        $declaredHere = array_map(
            static fn (ReflectionMethod $method): string => $method->getName(),
            array_filter(
                (new ReflectionClass(MarketplaceIndex::class))->getMethods(ReflectionMethod::IS_PUBLIC),
                static fn (ReflectionMethod $method): bool => $method->getDeclaringClass()->getName() === MarketplaceIndex::class,
            )
        );

        $this->assertSame(['render'], array_values($declaredHere));
    }

    public function test_an_unknown_category_never_throws_out_of_the_livewire_component(): void
    {
        // `assertSee` is enough: reaching it at all proves render() returned
        // a view rather than letting MarketplaceProductCategory::assertKnown()
        // throw out of the component.
        Livewire::test(MarketplaceIndex::class)
            ->set('category', 'KATEGORI_YANG_TIDAK_ADA')
            ->assertSee('Kategori tidak dikenali');
    }
}
