<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Marketplace;

use App\Domain\Marketplace\MarketplaceProductCategory;
use App\Domain\Marketplace\Models\Product;
use App\Domain\Marketplace\ProductCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The drift-detection test this batch's brief requires: this does NOT
 * compare the seeded database against `ProductCode::KNOWN_CODES` alone
 * (that would only prove the seed migration agrees with the enum, which is
 * true by construction — see the seed migration's own `up()`). It instead
 * RE-PARSES `docs/product/marketplace-catalog.md` directly, independently of
 * `ProductCode`, and asserts all three — the live catalogue document, the
 * `ProductCode` enum, and the seeded `products` table — agree. Editing the
 * seed data (or the enum) to disagree with the catalogue, OR editing the
 * catalogue without updating the code, fails this test either way.
 */
final class ProductCatalogueSeedTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return list<string>
     */
    private function codesFromLiveCatalogueDocument(): array
    {
        $path = base_path('docs/product/marketplace-catalog.md');
        $this->assertFileExists($path, 'Canonical catalogue document is missing.');

        $contents = file_get_contents($path);
        $this->assertIsString($contents);

        // Product codes appear in the catalogue only as backtick-quoted
        // ALL_CAPS_WITH_UNDERSCORES tokens (e.g. "`FLOWER_BOARD`"), exactly
        // once each, under the "Categories and minimum products" section.
        // The vendor-processing-statuses code block later in the same file
        // is a plain fenced code block, not backtick-quoted, so it is not
        // matched by this pattern.
        preg_match_all('/`([A-Z][A-Z0-9_]+)`/', $contents, $matches);

        return array_values(array_unique($matches[1]));
    }

    public function test_the_live_catalogue_document_still_names_exactly_nine_product_codes(): void
    {
        $codes = $this->codesFromLiveCatalogueDocument();

        $this->assertCount(
            9,
            $codes,
            'docs/product/marketplace-catalog.md no longer names exactly nine backtick-quoted product codes — '.
            'either the catalogue changed (requires a product-approval note per tasks.md) or this test\'s parser broke.'
        );
    }

    public function test_product_code_enum_matches_the_live_catalogue_document_exactly(): void
    {
        $fromDocument = $this->codesFromLiveCatalogueDocument();
        sort($fromDocument);

        $fromEnum = ProductCode::KNOWN_CODES;
        sort($fromEnum);

        $this->assertSame(
            $fromDocument,
            $fromEnum,
            'App\Domain\Marketplace\ProductCode::KNOWN_CODES has drifted from docs/product/marketplace-catalog.md.'
        );
    }

    public function test_exactly_nine_products_are_seeded(): void
    {
        $this->assertDatabaseCount('products', 9);
        $this->assertSame(9, count(ProductCode::KNOWN_CODES));
    }

    public function test_seeded_product_codes_match_the_live_catalogue_document_exactly(): void
    {
        $fromDocument = $this->codesFromLiveCatalogueDocument();
        sort($fromDocument);

        $seeded = Product::query()->pluck('code')->all();
        sort($seeded);

        $this->assertSame($fromDocument, $seeded);
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function catalogueProductsByCategory(): iterable
    {
        yield 'FLOWER_BOARD' => [ProductCode::FLOWER_BOARD, MarketplaceProductCategory::FLOWERS, 'Karangan Bunga Papan'];
        yield 'FLOWER_PETAL_PACKAGE' => [ProductCode::FLOWER_PETAL_PACKAGE, MarketplaceProductCategory::FLOWERS, 'Paket Bunga Tabur'];
        yield 'GRAVESTONE_GRANITE' => [ProductCode::GRAVESTONE_GRANITE, MarketplaceProductCategory::GRAVESTONES, 'Granit'];
        yield 'GRAVESTONE_MARBLE' => [ProductCode::GRAVESTONE_MARBLE, MarketplaceProductCategory::GRAVESTONES, 'Marmer'];
        yield 'GRAVESTONE_CALLIGRAPHY' => [ProductCode::GRAVESTONE_CALLIGRAPHY, MarketplaceProductCategory::GRAVESTONES, 'Kaligrafi'];
        yield 'GRAVE_CARE_MONTHLY' => [ProductCode::GRAVE_CARE_MONTHLY, MarketplaceProductCategory::GRAVE_CARE, 'Bulanan'];
        yield 'GRAVE_CARE_QUARTERLY' => [ProductCode::GRAVE_CARE_QUARTERLY, MarketplaceProductCategory::GRAVE_CARE, '3 Bulan'];
        yield 'GRAVE_CARE_SEMIANNUAL' => [ProductCode::GRAVE_CARE_SEMIANNUAL, MarketplaceProductCategory::GRAVE_CARE, '6 Bulan'];
        yield 'GRAVE_CARE_ANNUAL' => [ProductCode::GRAVE_CARE_ANNUAL, MarketplaceProductCategory::GRAVE_CARE, 'Tahunan'];
    }

    public function test_each_seeded_product_has_the_catalogues_exact_code_category_and_label(): void
    {
        foreach (self::catalogueProductsByCategory() as [$code, $category, $label]) {
            $product = Product::findByCode($code);

            $this->assertNotNull($product, "Expected a seeded product for [{$code}].");
            $this->assertSame($category, $product->category, "Product [{$code}] has an unexpected category.");
            $this->assertSame($label, $product->name, "Product [{$code}] has an unexpected name/label.");
            $this->assertNotSame('', trim($product->description), "Product [{$code}] has a blank description.");
            $this->assertTrue($product->is_active);
        }
    }

    /**
     * @return array<string, int>
     */
    private static function expectedDummyBasePricesByCode(): array
    {
        // Mirrors the dummy price figures written by
        // `2026_07_26_200100_add_dummy_vendor_pricing_and_photo_to_products.php`
        // — kept here as a plain literal map (not a re-import of that
        // migration's array) so this test independently proves the seeded
        // value matches what that migration documents, rather than trivially
        // agreeing with itself.
        return [
            ProductCode::FLOWER_BOARD => 350_000,
            ProductCode::FLOWER_PETAL_PACKAGE => 175_000,
            ProductCode::GRAVESTONE_GRANITE => 3_200_000,
            ProductCode::GRAVESTONE_MARBLE => 3_800_000,
            ProductCode::GRAVESTONE_CALLIGRAPHY => 3_950_000,
            ProductCode::GRAVE_CARE_MONTHLY => 150_000,
            ProductCode::GRAVE_CARE_QUARTERLY => 350_000,
            ProductCode::GRAVE_CARE_SEMIANNUAL => 600_000,
            ProductCode::GRAVE_CARE_ANNUAL => 950_000,
        ];
    }

    /**
     * Originally `test_no_product_has_a_fabricated_base_price`, asserting
     * every `base_price_idr` was `NULL` — see
     * `2026_07_26_180000_create_products_table.php`'s own doc block for why
     * that was the honest state at the time: no vendor existed to set a real
     * commercial price, so seeding a plausible-looking figure would have
     * been an unverified fact masquerading as data.
     *
     * That premise has since changed, for this environment only: the user
     * explicitly authorized clearly-fictional DUMMY vendor/pricing/photo
     * data so `dev.makam.co.id` — a real, intentionally public, non-
     * production host (ADR-0031) — has something realistic-looking to
     * render instead of blank price fields. See
     * `2026_07_26_200100_add_dummy_vendor_pricing_and_photo_to_products.php`'s
     * own doc block for the full authorization trail and the "future
     * real-vendor batch replaces this" caveat. This test is renamed and
     * flipped accordingly: it now asserts the dummy price IS present and
     * matches the documented figure, and that `price_version` was bumped to
     * `2` (a new "cut" of the row's price definition, per that column's own
     * documented intent) — not that prices are still absent.
     */
    public function test_every_product_has_the_documented_dummy_base_price_and_incremented_price_version(): void
    {
        $expected = self::expectedDummyBasePricesByCode();

        foreach (Product::query()->get() as $product) {
            $this->assertArrayHasKey($product->code, $expected, "No expected dummy price documented for [{$product->code}].");
            $this->assertNotNull($product->base_price_idr, "Product [{$product->code}] should have a seeded dummy base price.");
            $this->assertSame(
                $expected[$product->code],
                $product->base_price_idr,
                "Product [{$product->code}] does not match its documented dummy base price."
            );
            $this->assertSame(2, $product->price_version, "Product [{$product->code}] should be on price_version 2 after the dummy-price backfill.");
        }
    }

    public function test_every_product_has_a_clearly_fictional_vendor_name_and_a_placeholder_photo(): void
    {
        // Not a byte-for-byte string match against the migration (that would
        // just restate the migration's own array) — proves the shape/intent
        // instead: every row has a non-blank vendor name, and every photo
        // path points at this batch's hand-authored SVG illustration set
        // rather than a fabricated "photograph" of a nonexistent product.
        foreach (Product::query()->get() as $product) {
            $this->assertNotNull($product->vendor_name, "Product [{$product->code}] should have a placeholder vendor name.");
            $this->assertNotSame('', trim($product->vendor_name), "Product [{$product->code}] has a blank vendor name.");

            $this->assertNotNull($product->photo_path, "Product [{$product->code}] should have a placeholder photo path.");
            $this->assertStringStartsWith('images/marketplace/', $product->photo_path);
            $this->assertStringEndsWith('.svg', $product->photo_path);

            $this->assertFileExists(
                public_path($product->photo_path),
                "Product [{$product->code}]'s photo_path does not resolve to a real file under public/."
            );
        }
    }

    public function test_products_are_ordered_per_the_catalogues_own_listing_order(): void
    {
        $orderedCodes = Product::query()->orderBy('sort_order')->pluck('code')->all();

        $this->assertSame(ProductCode::KNOWN_CODES, $orderedCodes);
    }
}
