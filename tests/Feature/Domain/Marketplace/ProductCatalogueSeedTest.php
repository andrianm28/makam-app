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

    public function test_no_product_has_a_fabricated_base_price(): void
    {
        // See 2026_07_26_180000_create_products_table.php's own doc block:
        // no vendor exists yet to set a real commercial price, so every
        // seeded row is deliberately left unpriced rather than seeded with
        // an unverified figure.
        foreach (Product::query()->get() as $product) {
            $this->assertNull($product->base_price_idr, "Product [{$product->code}] should not have a seeded base price yet.");
            $this->assertSame(1, $product->price_version);
        }
    }

    public function test_products_are_ordered_per_the_catalogues_own_listing_order(): void
    {
        $orderedCodes = Product::query()->orderBy('sort_order')->pluck('code')->all();

        $this->assertSame(ProductCode::KNOWN_CODES, $orderedCodes);
    }
}
