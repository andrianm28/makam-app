<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Public\Marketplace;

use App\Domain\Marketplace\Models\Product;
use App\Domain\Marketplace\Models\ProductVariant;
use App\Domain\Marketplace\ProductCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
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
        // No CASCADE is needed here (unlike that test): product_variants
        // holds a foreign key TO products, and no table holds one against
        // product_variants, so a plain drop succeeds on both SQLite and
        // Postgres.
        $product = Product::findByCode(ProductCode::GRAVESTONE_GRANITE);
        $this->assertNotNull($product);

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

    public function test_the_detail_page_offers_no_cart_or_checkout_affordance(): void
    {
        // Browse only — cart/checkout are Sprint 11-12 and need a Tier-3
        // payment decision. AC3's later steps must not be implied.
        $response = $this->get('/marketplace/produk/'.ProductCode::GRAVESTONE_GRANITE);

        $response->assertOk();
        $response->assertDontSee('Tambah ke Keranjang');
        $response->assertDontSee('/marketplace/keranjang');
        $response->assertDontSee('/marketplace/checkout');
        // §6.7 — the absence is stated as a pending state, not left silent.
        $response->assertSee('Pemesanan online belum tersedia');
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
