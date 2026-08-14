<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Public\Marketplace;

use App\Domain\Marketplace\Actions\AddToCart;
use App\Domain\Marketplace\AvailabilityMode;
use App\Domain\Marketplace\EvidenceRequirement;
use App\Domain\Marketplace\Models\Cart as CartModel;
use App\Domain\Marketplace\Models\Product;
use App\Domain\Marketplace\Models\ProductVariant;
use App\Domain\Marketplace\Models\Vendor;
use App\Domain\Marketplace\Models\VendorListing;
use App\Domain\Marketplace\ProductCode;
use App\Livewire\Public\Marketplace\ProductDetail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

/**
 * `/marketplace/produk/{productCode}` — PUB-021, Sprint 4 S4-T8, spec
 * `funeral-marketplace-and-vendor-portal` AC1-AC3. Same conventions as
 * `MarketplaceIndexRouteTest`: real seeded catalogue, no factories, product
 * codes referenced through `ProductCode` rather than retyped.
 *
 * These tests share `MarketplaceIndexRouteTest`'s dependency on the two
 * routes S4-T8 requested but does not own — see that file's own doc block
 * for the exact `Route::get(...)` lines.
 */
final class ProductDetailRouteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    /**
     * A real active offer, as the L10 vendor panel would create one. The
     * catalogue seed ships zero listings, so every test that exercises the
     * add affordance creates its own.
     */
    private function listing(string $vendorName, string $code, int $price = 150_000): VendorListing
    {
        $vendor = Vendor::create(['name' => $vendorName, 'is_active' => true]);

        return VendorListing::create([
            'vendor_id' => $vendor->id,
            'product_id' => Product::findByCode($code)->id,
            'price_minor' => $price,
            'price_version' => 1,
            'availability_mode' => AvailabilityMode::STOCKED,
            'stock_quantity' => 10,
            'evidence_requirement' => EvidenceRequirement::NONE,
            'is_active' => true,
        ]);
    }

    public function test_every_seeded_product_code_has_a_reachable_detail_page(): void
    {
        foreach (ProductCode::KNOWN_CODES as $code) {
            $product = Product::findByCode($code);
            $this->assertNotNull($product, "Seeded product [{$code}] is missing.");

            $response = $this->get("/marketplace/produk/{$code}");

            $response->assertOk();
            $response->assertSee($product->name);
        }
    }

    public function test_a_gravestone_product_shows_its_seeded_variants(): void
    {
        // Only GRAVESTONE_* products carry product_variants rows
        // (ProductCode::GRAVESTONE_CODES, enforced on write by
        // ProductVariant::booted()). The seed ships two per gravestone.
        $response = $this->get('/marketplace/produk/'.ProductCode::GRAVESTONE_GRANITE);

        $response->assertOk();
        $response->assertSee('Pilihan Varian');
        $response->assertSee('Ukuran');
        $response->assertSee('Bahan');
        $response->assertSee('60 x 80 cm');
        $response->assertSee('Granit Hitam');
        $response->assertSee('80 x 100 cm');
    }

    public function test_the_calligraphy_product_shows_its_calligraphy_style_and_inscription_example(): void
    {
        $response = $this->get('/marketplace/produk/'.ProductCode::GRAVESTONE_CALLIGRAPHY);

        $response->assertOk();
        $response->assertSee('Gaya kaligrafi');
        $response->assertSee('Naskhi');
        $response->assertSee('Diwani');
        $response->assertSee('Contoh teks inskripsi');
    }

    public function test_a_product_family_with_no_variant_axes_says_so_instead_of_showing_an_empty_state(): void
    {
        // FLOWER_* and GRAVE_CARE_* genuinely have no variant attributes in
        // the catalogue — that is not the same thing as "variants are
        // missing", and the two must not read alike.
        foreach ([ProductCode::FLOWER_BOARD, ProductCode::GRAVE_CARE_ANNUAL] as $code) {
            $response = $this->get("/marketplace/produk/{$code}");

            $response->assertOk();
            $response->assertSee('Produk ini tidak memiliki pilihan varian');
            $response->assertDontSee('Belum ada varian yang terdaftar.');
        }
    }

    public function test_a_gravestone_with_zero_variant_rows_shows_the_empty_state_not_the_no_axes_message(): void
    {
        $product = Product::findByCode(ProductCode::GRAVESTONE_MARBLE);
        $this->assertNotNull($product);

        ProductVariant::query()->where('product_id', $product->id)->delete();

        $response = $this->get('/marketplace/produk/'.ProductCode::GRAVESTONE_MARBLE);

        $response->assertOk();
        $response->assertSee('Belum ada varian yang terdaftar.');
        $response->assertDontSee('Produk ini tidak memiliki pilihan varian');
        $response->assertSee('Hubungi Customer Service');
    }

    public function test_a_variant_read_failure_degrades_the_panel_without_taking_the_page_down(): void
    {
        // design-system.md §6.5 — proven by dropping the real table inside
        // the test transaction, the idiom EloquentGateRegistrySourceTest
        // established, rather than by mocking MarketplaceCatalogQuery.
        // The checkout lane (L11) added cart_items.product_variant_id and
        // marketplace_order_items.product_variant_id -> product_variants, so
        // those two are dropped first — a bare DROP TABLE product_variants
        // now fails on Postgres with 2BP01 while anything references it.
        $product = Product::findByCode(ProductCode::GRAVESTONE_GRANITE);
        $this->assertNotNull($product);

        Schema::dropIfExists('marketplace_order_items');
        Schema::dropIfExists('cart_items');
        Schema::drop('product_variants');

        $response = $this->get('/marketplace/produk/'.ProductCode::GRAVESTONE_GRANITE);

        $response->assertOk();
        $response->assertSee('Pilihan varian sedang tidak dapat dimuat');
        // Primary content survives: the product's own name and its support
        // path are still on the page.
        $response->assertSee($product->name);
        $response->assertSee('/bantuan');
    }

    public function test_an_unknown_product_code_404s(): void
    {
        $this->get('/marketplace/produk/KODE_PRODUK_YANG_TIDAK_ADA')->assertNotFound();
    }

    public function test_a_deactivated_product_404s_indistinguishably_from_a_code_that_never_existed(): void
    {
        // findActiveByCode() returns null for both cases on purpose; a
        // distinct "not available" page would leak that the row exists.
        $product = Product::findByCode(ProductCode::FLOWER_PETAL_PACKAGE);
        $this->assertNotNull($product);

        $product->is_active = false;
        $product->save();

        $deactivated = $this->get('/marketplace/produk/'.ProductCode::FLOWER_PETAL_PACKAGE);
        $neverExisted = $this->get('/marketplace/produk/KODE_PRODUK_YANG_TIDAK_ADA');

        $deactivated->assertNotFound();
        $this->assertSame($neverExisted->getStatusCode(), $deactivated->getStatusCode());
        $deactivated->assertDontSee($product->name);
    }

    public function test_the_detail_page_offers_add_to_cart_when_a_vendor_listing_exists(): void
    {
        // The browse-only pin W-2 placed here is retired: the cart and
        // checkout lanes have shipped, so the detail page now offers the
        // add affordance whenever a real vendor offer exists (AC3's
        // browse -> select -> cart chain). The structural assertions catch
        // the affordance whatever its label, with the string checks kept as
        // belt-and-braces.
        $listing = $this->listing('Vendor Nyata', ProductCode::GRAVESTONE_GRANITE);

        $response = $this->get('/marketplace/produk/'.ProductCode::GRAVESTONE_GRANITE);

        $response->assertOk();
        $response->assertSee('wire:click="addToCart"', escape: false);
        $response->assertSee('Tambah ke Keranjang');
        // The REAL offer replaces the dummy estimate at the point of
        // adding: the listing's own price and vendor, no fabricated marker.
        $response->assertSee('Vendor Nyata');
        $response->assertSee($listing->priceMoney()->format());
        $response->assertDontSee('Estimasi internal (data contoh)');
        $response->assertDontSee('(vendor contoh)');
        // The "not available" state is gone for a product that IS available.
        $response->assertDontSee('Pemesanan online belum tersedia');
        // AC4 is stated right where the item is added.
        $response->assertSee('checkout hanya dapat memuat produk dari satu vendor');
    }

    public function test_a_product_with_no_vendor_listing_offers_no_add_to_cart_and_states_so(): void
    {
        // The catalogue is seeded with zero listings — the honest state is
        // "no offer yet", not a fabricated add button that would have
        // nothing to add.
        $response = $this->get('/marketplace/produk/'.ProductCode::FLOWER_BOARD);

        $response->assertOk();
        $response->assertDontSee('wire:click="addToCart"', escape: false);
        $response->assertDontSee('Tambah ke Keranjang');
        $response->assertSee('Pemesanan online belum tersedia');
        $response->assertSee('Hubungi Customer Service');
    }

    public function test_adding_from_the_detail_page_puts_the_item_in_the_cart_and_redirects_to_it(): void
    {
        $listing = $this->listing('Vendor Nyata', ProductCode::GRAVESTONE_GRANITE);

        Livewire::test(ProductDetail::class, ['productCode' => ProductCode::GRAVESTONE_GRANITE])
            ->call('addToCart')
            ->assertRedirect(route('marketplace.cart'));

        $cart = CartModel::where('session_ref', session()->getId())->firstOrFail();
        $this->assertSame(1, $cart->items()->count());
        $this->assertSame($listing->id, $cart->items()->first()->vendor_listing_id);
        $this->assertSame($listing->vendor_id, $cart->vendor_id);
    }

    public function test_adding_from_the_detail_page_respects_the_single_vendor_conflict(): void
    {
        // The cart is already locked to vendor A (via the same AddToCart the
        // cart screen calls); the detail page adds vendor B's listing. The
        // conflict must surface with both vendors named and change nothing
        // until the customer explicitly resolves it.
        $a = $this->listing('Vendor A', ProductCode::FLOWER_BOARD);
        $b = $this->listing('Vendor B', ProductCode::GRAVESTONE_GRANITE);
        $cart = CartModel::create(['customer_ref' => null, 'session_ref' => session()->getId()]);
        (new AddToCart)->handle($cart, $a, 1);

        $component = Livewire::test(ProductDetail::class, ['productCode' => ProductCode::GRAVESTONE_GRANITE])
            ->call('addToCart')
            ->assertSet('conflictOpen', true)
            ->assertSee('Vendor A')
            ->assertSee('Vendor B')
            // AC4: the constraint is stated, and both resolutions are offered.
            ->assertSee('Ganti keranjang')
            ->assertSee('Selesaikan pesanan ini dulu');

        // The conflict never mutates the cart.
        $this->assertSame(1, $cart->fresh()->items()->count());
        $this->assertSame($a->id, $cart->fresh()->items()->first()->vendor_listing_id);

        // Dismissing keeps the existing items and sends no one anywhere.
        $component->call('dismissConflict')->assertSet('conflictOpen', false);
        $this->assertSame(1, $cart->fresh()->items()->count());
        $this->assertSame($a->vendor_id, $cart->fresh()->vendor_id);
    }

    public function test_resolving_the_conflict_from_the_detail_page_replaces_the_cart_explicitly(): void
    {
        $a = $this->listing('Vendor A', ProductCode::FLOWER_BOARD);
        $b = $this->listing('Vendor B', ProductCode::GRAVESTONE_GRANITE);
        $cart = CartModel::create(['customer_ref' => null, 'session_ref' => session()->getId()]);
        (new AddToCart)->handle($cart, $a, 2);

        $component = Livewire::test(ProductDetail::class, ['productCode' => ProductCode::GRAVESTONE_GRANITE])
            ->call('addToCart')
            ->assertSet('conflictOpen', true);

        // Only the explicit replace clears anything — the old line is gone,
        // the incoming vendor's offer takes its place, and the customer
        // lands on the cart screen where the replaced cart is visible.
        $component->call('resolveConflictByReplacing');
        $component->assertRedirect(route('marketplace.cart'));

        $cart = $cart->fresh();
        $this->assertSame(1, $cart->items()->count());
        $this->assertSame($b->id, $cart->items()->first()->vendor_listing_id);
        $this->assertSame($b->vendor_id, $cart->vendor_id);
    }

    public function test_the_component_exposes_no_livewire_actions_to_call(): void
    {
        // W-2 mirror of MarketplaceIndexRouteTest::test_the_component_exposes_
        // no_livewire_actions_to_call. ProductDetail is the exact page a
        // per-product "Tambah ke Keranjang" button would live on, so its
        // action surface must be pinned structurally too. mount() is public
        // by Livewire contract but is not callable from the browser; the
        // add-to-cart surface (this lane's fix wave) adds addToCart and the
        // two conflict resolutions — if a future batch adds a real action,
        // this SHOULD fail — that is the prompt to restore a loading state
        // with it.
        $declaredHere = array_map(
            static fn (ReflectionMethod $method): string => $method->getName(),
            array_filter(
                (new ReflectionClass(ProductDetail::class))->getMethods(ReflectionMethod::IS_PUBLIC),
                static fn (ReflectionMethod $method): bool => $method->getDeclaringClass()->getName() === ProductDetail::class,
            )
        );

        $this->assertSame(
            ['mount', 'addToCart', 'resolveConflictByReplacing', 'dismissConflict', 'render'],
            array_values($declaredHere)
        );
    }

    public function test_the_detail_page_states_the_single_vendor_per_checkout_constraint_as_a_note(): void
    {
        // AC4 requires the constraint be made explicit to the user. S4-T8
        // states it; it deliberately builds no enforcement mechanism,
        // because there is no cart for one to act on.
        $response = $this->get('/marketplace/produk/'.ProductCode::FLOWER_BOARD);

        $response->assertOk();
        $response->assertSee('satu checkout hanya dapat memuat produk dari satu vendor');
    }

    public function test_placeholder_variant_preview_image_paths_are_never_rendered(): void
    {
        // The six seeded variants carry
        // `marketplace/gravestone-variants/placeholder-*.jpg` paths, and the
        // seed migration's own doc block says no file exists at any of them.
        // Rendering one would put a broken image on every gravestone page.
        $response = $this->get('/marketplace/produk/'.ProductCode::GRAVESTONE_GRANITE);

        $response->assertOk();
        $response->assertDontSee('placeholder-gravestone-granite-hitam.jpg');
        $response->assertDontSee('marketplace/gravestone-variants');
    }

    public function test_the_support_escape_hatch_is_present_on_the_detail_page(): void
    {
        $response = $this->get('/marketplace/produk/'.ProductCode::GRAVE_CARE_MONTHLY);

        $response->assertOk();
        $response->assertSee('/bantuan');
        $response->assertSee('Hubungi Customer Service');
    }

    public function test_a_rendered_dummy_price_is_always_accompanied_by_its_estimated_source_line(): void
    {
        // W-1 regression (Critical), detail page half: this screen renders
        // `base_price_idr` at text-2xl directly above copy that tells the
        // visitor to phone customer service to order "now" — so the source
        // line MUST render with it. design-system.md §2.3 DO.
        $response = $this->get('/marketplace/produk/'.ProductCode::GRAVESTONE_GRANITE);

        $response->assertOk();
        $response->assertSee('Estimasi internal (data contoh)');
    }

    public function test_a_rendered_vendor_name_always_carries_the_fabricated_data_marker(): void
    {
        // W-1 regression (Critical), detail page half — "Vendor:" must never
        // render a bare invented trading name.
        $response = $this->get('/marketplace/produk/'.ProductCode::GRAVESTONE_GRANITE);

        $response->assertOk();
        $response->assertSee('(vendor contoh)');
    }

    public function test_viewing_a_product_never_mutates_the_catalogue(): void
    {
        // §6.6 — a detail page is a pure read; repeated views are safe.
        $productsBefore = Product::query()->count();
        $variantsBefore = ProductVariant::query()->count();

        $this->get('/marketplace/produk/'.ProductCode::GRAVESTONE_MARBLE)->assertOk();
        $this->get('/marketplace/produk/'.ProductCode::GRAVESTONE_MARBLE)->assertOk();
        $this->get('/marketplace/produk/'.ProductCode::GRAVESTONE_MARBLE)->assertOk();

        $this->assertSame($productsBefore, Product::query()->count());
        $this->assertSame($variantsBefore, ProductVariant::query()->count());
    }
}
