<?php

declare(strict_types=1);

namespace Tests\Feature\Migrations;

use App\Domain\Marketplace\ProductCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * `RefreshDatabase` applies every migration once per PHPUnit process, so the
 * migration itself is gated behind
 * `config('example_data.seed_realistic_marketplace_pricing')` (default
 * false; see `config/example_data.php`) and is a no-op during the ambient
 * migrate that happens in `setUp()`. Every positive-path test here sets the
 * flag explicitly and invokes `up()` directly, mirroring
 * `Tests\Feature\Migrations\SeedE2eAdminVendorTestUsersTest`.
 */
final class SeedRealisticMarketplacePricingFixturesTest extends TestCase
{
    use RefreshDatabase;

    private const string MIGRATION_PATH = 'migrations/2026_08_25_140000_seed_realistic_marketplace_pricing_fixtures.php';

    public function test_it_seeds_three_fictional_vendors_grouped_by_trade(): void
    {
        config(['example_data.seed_realistic_marketplace_pricing' => true]);
        (require database_path(self::MIGRATION_PATH))->up();

        $this->assertDatabaseHas('vendors', ['name' => 'Toko Bunga Contoh Melati Sejahtera']);
        $this->assertDatabaseHas('vendors', ['name' => 'CV Batu Nisan Contoh Abadi Prima']);
        $this->assertDatabaseHas('vendors', ['name' => 'UD Perawatan Makam Contoh Damai Nusantara']);
    }

    public function test_it_seeds_researched_prices_for_every_known_product_code(): void
    {
        config(['example_data.seed_realistic_marketplace_pricing' => true]);
        (require database_path(self::MIGRATION_PATH))->up();

        $expected = [
            ProductCode::FLOWER_BOARD => 650_000,
            ProductCode::FLOWER_PETAL_PACKAGE => 150_000,
            ProductCode::GRAVESTONE_GRANITE => 2_500_000,
            ProductCode::GRAVESTONE_MARBLE => 1_200_000,
            ProductCode::GRAVESTONE_CALLIGRAPHY => 7_500_000,
            ProductCode::GRAVE_CARE_MONTHLY => 150_000,
            ProductCode::GRAVE_CARE_QUARTERLY => 400_000,
            ProductCode::GRAVE_CARE_SEMIANNUAL => 750_000,
            ProductCode::GRAVE_CARE_ANNUAL => 1_350_000,
        ];

        foreach ($expected as $code => $priceMinor) {
            $productId = DB::table('products')->where('code', $code)->value('id');
            $this->assertNotNull($productId, "expected a real products row for [{$code}]");

            $this->assertDatabaseHas('vendor_listings', [
                'product_id' => $productId,
                'price_minor' => $priceMinor,
            ]);
        }
    }

    /**
     * Every listing this fixture creates must be usable end to end: a
     * vendor with zero `service_areas` rows can never complete checkout
     * (`VendorListingExampleData::serviceAreas()`'s own doc block, verified
     * live on dev) — this fixture's three vendors must not repeat that gap.
     */
    public function test_every_seeded_vendor_has_at_least_one_service_area(): void
    {
        config(['example_data.seed_realistic_marketplace_pricing' => true]);
        (require database_path(self::MIGRATION_PATH))->up();

        foreach (['Toko Bunga Contoh Melati Sejahtera', 'CV Batu Nisan Contoh Abadi Prima', 'UD Perawatan Makam Contoh Damai Nusantara'] as $name) {
            $vendorId = DB::table('vendors')->where('name', $name)->value('id');
            $this->assertNotNull($vendorId);

            $this->assertGreaterThan(
                0,
                DB::table('service_areas')->where('vendor_id', $vendorId)->count(),
                "expected at least one service area for [{$name}]"
            );
        }
    }

    public function test_it_is_idempotent_on_a_re_run(): void
    {
        config(['example_data.seed_realistic_marketplace_pricing' => true]);

        $migration = require database_path(self::MIGRATION_PATH);

        $migration->up();
        $migration->up();

        $this->assertSame(
            1,
            DB::table('vendors')->where('name', 'Toko Bunga Contoh Melati Sejahtera')->count(),
            're-running the migration must not create a duplicate vendor'
        );
    }

    /**
     * This is the test that actually protects the pre-existing suite going
     * forward: with the flag left at its real default (unset — false),
     * `up()` must write nothing at all.
     */
    public function test_it_is_a_no_op_when_the_flag_is_left_at_its_default(): void
    {
        $this->assertFalse(
            config('example_data.seed_realistic_marketplace_pricing'),
            'this test only proves anything if the flag is genuinely at its default-false value'
        );

        (require database_path(self::MIGRATION_PATH))->up();

        $this->assertDatabaseMissing('vendors', ['name' => 'Toko Bunga Contoh Melati Sejahtera']);
        $this->assertDatabaseMissing('vendors', ['name' => 'CV Batu Nisan Contoh Abadi Prima']);
        $this->assertDatabaseMissing('vendors', ['name' => 'UD Perawatan Makam Contoh Damai Nusantara']);
    }

    /**
     * The independent `app()->isProduction()` guard: even with the config
     * flag explicitly true, production must refuse to seed. Mirrors
     * `SeedE2eAdminVendorTestUsersTest`'s equivalent production-guard
     * coverage for the sibling e2e fixture migration.
     */
    public function test_it_refuses_to_seed_in_production_even_with_the_flag_true(): void
    {
        config(['example_data.seed_realistic_marketplace_pricing' => true]);

        // `isProduction()` reads the container's bound 'env', not
        // `config('app.env')` — `instance()` is the real way to fake it in
        // a test, matching how `Illuminate\Foundation\Application::
        // detectEnvironment()` itself populates that binding at boot.
        app()->instance('env', 'production');

        $this->assertTrue(app()->isProduction());

        (require database_path(self::MIGRATION_PATH))->up();

        $this->assertDatabaseMissing('vendors', ['name' => 'Toko Bunga Contoh Melati Sejahtera']);
    }
}
