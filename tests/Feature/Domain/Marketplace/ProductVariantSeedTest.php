<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Marketplace;

use App\Domain\Marketplace\MarketplaceCatalogQuery;
use App\Domain\Marketplace\Models\Product;
use App\Domain\Marketplace\Models\ProductVariant;
use App\Domain\Marketplace\ProductCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Proves the variant-axis scoping decision documented in
 * `2026_07_26_180100_create_product_variants_table.php`: only the three
 * Batu Nisan (`GRAVESTONE_*`) products carry `product_variants` rows, those
 * rows populate the catalogue's named variant attributes (size, material,
 * color, plus calligraphy style/inscription/preview for the calligraphy
 * product specifically), and every other product carries zero variant rows.
 */
final class ProductVariantSeedTest extends TestCase
{
    use RefreshDatabase;

    public function test_exactly_six_variant_rows_are_seeded_two_per_gravestone_product(): void
    {
        $this->assertDatabaseCount('product_variants', 6);

        foreach (ProductCode::GRAVESTONE_CODES as $code) {
            $product = Product::findByCode($code);
            $this->assertNotNull($product);

            $this->assertSame(
                2,
                ProductVariant::query()->where('product_id', $product->id)->count(),
                "Expected exactly 2 seeded variants for [{$code}]."
            );
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function nonGravestoneCodes(): iterable
    {
        foreach (ProductCode::KNOWN_CODES as $code) {
            if (! in_array($code, ProductCode::GRAVESTONE_CODES, true)) {
                yield $code => [$code];
            }
        }
    }

    public function test_flower_and_grave_care_products_carry_zero_variant_rows(): void
    {
        foreach (self::nonGravestoneCodes() as [$code]) {
            $product = Product::findByCode($code);
            $this->assertNotNull($product);

            $this->assertFalse($product->hasVariantAxes(), "[{$code}] should not be flagged as requiring variant axes.");
            $this->assertSame(0, ProductVariant::query()->where('product_id', $product->id)->count(), "[{$code}] should have zero seeded variants.");
            $this->assertCount(0, MarketplaceCatalogQuery::variantsFor($product));
        }
    }

    public function test_granite_and_marble_variants_populate_size_material_and_color_but_not_calligraphy_axes(): void
    {
        foreach ([ProductCode::GRAVESTONE_GRANITE, ProductCode::GRAVESTONE_MARBLE] as $code) {
            $product = Product::findByCode($code);
            $variants = MarketplaceCatalogQuery::variantsFor($product);

            $this->assertCount(2, $variants);

            foreach ($variants as $variant) {
                $this->assertNotSame('', trim($variant->size), "[{$code}] variant #{$variant->id} has a blank size.");
                $this->assertNotSame('', trim($variant->material), "[{$code}] variant #{$variant->id} has a blank material.");
                $this->assertNotSame('', trim($variant->color), "[{$code}] variant #{$variant->id} has a blank color.");
                $this->assertNotNull($variant->preview_image_path, "[{$code}] variant #{$variant->id} has no preview image path.");

                $this->assertNull($variant->calligraphy_style, "[{$code}] variant #{$variant->id} should not have a calligraphy style.");
                $this->assertNull($variant->inscription_text_example, "[{$code}] variant #{$variant->id} should not have inscription text.");
            }
        }
    }

    public function test_calligraphy_variants_populate_every_catalogue_named_variant_axis(): void
    {
        $product = Product::findByCode(ProductCode::GRAVESTONE_CALLIGRAPHY);
        $variants = MarketplaceCatalogQuery::variantsFor($product);

        $this->assertCount(2, $variants);

        $seenStyles = [];

        foreach ($variants as $variant) {
            $this->assertNotSame('', trim($variant->size));
            $this->assertNotSame('', trim($variant->material));
            $this->assertNotSame('', trim($variant->color));
            $this->assertNotNull($variant->calligraphy_style, "Calligraphy variant #{$variant->id} has no calligraphy style.");
            $this->assertNotSame('', trim((string) $variant->inscription_text_example), "Calligraphy variant #{$variant->id} has no inscription text example.");
            $this->assertNotNull($variant->preview_image_path, "Calligraphy variant #{$variant->id} has no preview image path.");

            $seenStyles[] = $variant->calligraphy_style;
        }

        // "A couple of calligraphy styles" per this batch's brief — proves
        // the two seeded rows are not accidental duplicates of each other.
        $this->assertCount(2, array_unique($seenStyles));
    }

    public function test_a_variant_cannot_be_attached_to_a_non_gravestone_product(): void
    {
        $flowerProduct = Product::findByCode(ProductCode::FLOWER_BOARD);
        $this->assertNotNull($flowerProduct);

        $this->expectException(InvalidArgumentException::class);

        ProductVariant::query()->create([
            'product_id' => $flowerProduct->id,
            'size' => '1 x 2 m',
            'material' => 'Kayu',
            'color' => 'Cokelat',
        ]);
    }
}
