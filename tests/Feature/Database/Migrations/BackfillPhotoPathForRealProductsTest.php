<?php

declare(strict_types=1);

namespace Tests\Feature\Database\Migrations;

use App\Domain\Marketplace\Models\Product;
use App\Domain\Marketplace\ProductCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `2026_08_24_110000_backfill_photo_path_for_real_products.php` runs as part
 * of the normal migration set `RefreshDatabase` applies, so every one of the
 * 9 real, seeded products (`2026_07_26_180200_seed_marketplace_products_and_
 * variants.php`) already carries the backfilled `photo_path` by the time
 * each test method starts — these tests assert the end state the migration
 * produces, not the migration class directly.
 */
final class BackfillPhotoPathForRealProductsTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_known_product_code_has_a_real_matching_svg_photo_path(): void
    {
        $expected = [
            ProductCode::FLOWER_BOARD => 'images/marketplace/flower-board.svg',
            ProductCode::FLOWER_PETAL_PACKAGE => 'images/marketplace/flower-petal-package.svg',
            ProductCode::GRAVESTONE_GRANITE => 'images/marketplace/gravestone-granite.svg',
            ProductCode::GRAVESTONE_MARBLE => 'images/marketplace/gravestone-marble.svg',
            ProductCode::GRAVESTONE_CALLIGRAPHY => 'images/marketplace/gravestone-calligraphy.svg',
            ProductCode::GRAVE_CARE_MONTHLY => 'images/marketplace/grave-care-monthly.svg',
            ProductCode::GRAVE_CARE_QUARTERLY => 'images/marketplace/grave-care-quarterly.svg',
            ProductCode::GRAVE_CARE_SEMIANNUAL => 'images/marketplace/grave-care-semiannual.svg',
            ProductCode::GRAVE_CARE_ANNUAL => 'images/marketplace/grave-care-annual.svg',
        ];

        foreach ($expected as $code => $photoPath) {
            $product = Product::query()->where('code', $code)->first();

            $this->assertNotNull($product, "product code [{$code}] not found");
            $this->assertSame($photoPath, $product->photo_path, "product code [{$code}] has the wrong photo_path");
            $this->assertFileExists(public_path($photoPath), "backfilled photo_path [{$photoPath}] does not point at a real file");
        }
    }

    public function test_every_known_product_code_is_covered_no_silent_gap(): void
    {
        $withPhoto = Product::query()->whereIn('code', ProductCode::KNOWN_CODES)->whereNotNull('photo_path')->count();

        $this->assertSame(
            count(ProductCode::KNOWN_CODES),
            $withPhoto,
            'every known product code must have a non-null photo_path after the backfill migration'
        );
    }
}
