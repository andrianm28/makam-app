<?php

declare(strict_types=1);

namespace Tests\Feature\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * DB-05 (batch M3a) regression coverage for the six fixture migrations that
 * previously ran with NO environment guard at all — confirmed to already
 * have run once, unconditionally, in `setUp()`'s ambient `RefreshDatabase`
 * migrate BEFORE any test body executes. These six default their
 * `example_data.*` flag to TRUE, unlike `seed_realistic_marketplace_
 * pricing`, precisely so today's default dev/staging/CI behaviour is
 * unchanged — see `config/example_data.php`'s own doc block.
 *
 * Every "refuses in production" test below re-invokes `up()` a SECOND time
 * against a simulated production environment and asserts the row count is
 * UNCHANGED from the ambient (already-seeded) count, rather than deleting
 * rows first and asserting zero — deleting first would either violate a
 * real RESTRICT FK from dependent example data seeded by a LATER migration
 * (cemeteries <- grave_records) or require re-establishing every table's
 * seed order by hand. Asserting "no new rows were added" is both simpler
 * and the more precise thing this guard actually promises: it does not
 * promise to delete data that already exists, only to refuse writing MORE.
 */
final class Db05UnguardedFixtureMigrationsTest extends TestCase
{
    use RefreshDatabase;

    private function simulateProduction(): void
    {
        // `isProduction()` reads the container's bound 'env', not
        // `config('app.env')` — `instance()` is the real way to fake it in
        // a test, matching how `Illuminate\Foundation\Application::
        // detectEnvironment()` itself populates that binding at boot.
        app()->instance('env', 'production');

        $this->assertTrue(app()->isProduction());
    }

    public function test_seed_cemeteries_and_capability_profiles_refuses_in_production(): void
    {
        $before = DB::table('cemeteries')->count();
        $this->assertGreaterThan(0, $before, 'ambient non-production migrate should have already seeded cemeteries');

        $this->simulateProduction();
        (require database_path('migrations/2026_07_26_190300_seed_cemeteries_and_capability_profiles.php'))->up();

        $this->assertSame($before, DB::table('cemeteries')->count());
    }

    public function test_add_dummy_vendor_pricing_and_photo_to_products_refuses_in_production(): void
    {
        $before = DB::table('products')->whereNotNull('vendor_name')->count();
        $this->assertGreaterThan(0, $before, 'ambient non-production migrate should have already seeded dummy vendor pricing');

        // This migration's Schema::table() column-add runs unconditionally
        // (safe, expand-only) BEFORE the data guard — it already ran once
        // via the ambient RefreshDatabase migrate in setUp(), so the
        // columns already exist. Drop them first so re-invoking up() can
        // re-add them without a "column already exists" error; this
        // isolates the assertion to the DATA guard this test targets, and
        // re-adding nullable columns is itself harmless/idempotent-safe.
        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn(['vendor_name', 'photo_path']);
        });
        $this->simulateProduction();

        (require database_path('migrations/2026_07_26_200100_add_dummy_vendor_pricing_and_photo_to_products.php'))->up();

        $this->assertSame(0, DB::table('products')->whereNotNull('vendor_name')->count());
    }

    public function test_backfill_dummy_map_price_and_photo_refuses_in_production(): void
    {
        $before = DB::table('cemeteries')->whereNotNull('price_source')->count();
        $this->assertGreaterThan(0, $before, 'ambient non-production migrate should have already backfilled price_source');

        DB::table('cemeteries')->update(['price_source' => null]);
        $this->simulateProduction();

        (require database_path('migrations/2026_07_26_210000_backfill_dummy_map_price_and_photo_for_seeded_cemeteries.php'))->up();

        $this->assertSame(0, DB::table('cemeteries')->whereNotNull('price_source')->count());
    }

    public function test_seed_example_grave_records_refuses_in_production(): void
    {
        $before = DB::table('grave_records')->count();
        $this->assertGreaterThan(0, $before, 'ambient non-production migrate should have already seeded grave records');

        $this->simulateProduction();
        (require database_path('migrations/2026_08_08_100010_seed_example_grave_records.php'))->up();

        $this->assertSame($before, DB::table('grave_records')->count());
    }

    public function test_seed_vendors_and_listings_refuses_in_production(): void
    {
        $vendorsBefore = DB::table('vendors')->count();
        $listingsBefore = DB::table('vendor_listings')->count();
        $this->assertGreaterThan(0, $vendorsBefore, 'ambient non-production migrate should have already seeded vendors');
        $this->assertGreaterThan(0, $listingsBefore, 'ambient non-production migrate should have already seeded vendor listings');

        $this->simulateProduction();
        (require database_path('migrations/2026_08_14_100000_seed_vendors_and_listings.php'))->up();

        $this->assertSame($vendorsBefore, DB::table('vendors')->count());
        $this->assertSame($listingsBefore, DB::table('vendor_listings')->count());
    }

    public function test_seed_service_areas_for_example_vendors_refuses_in_production(): void
    {
        $before = DB::table('service_areas')->count();
        $this->assertGreaterThan(0, $before, 'ambient non-production migrate should have already seeded service areas');

        $this->simulateProduction();
        (require database_path('migrations/2026_08_14_100010_seed_service_areas_for_example_vendors.php'))->up();

        $this->assertSame($before, DB::table('service_areas')->count());
    }

    /**
     * The default-true direction itself, spelled out explicitly rather
     * than only inferred from the ambient-seeding assertions above: an
     * operator can still disable any one of these six in a NON-production
     * environment via its config flag, without touching the migration.
     */
    public function test_the_config_flag_can_disable_seeding_outside_production(): void
    {
        $before = DB::table('service_areas')->count();
        $this->assertGreaterThan(0, $before);

        config(['example_data.seed_service_areas_for_example_vendors' => false]);

        (require database_path('migrations/2026_08_14_100010_seed_service_areas_for_example_vendors.php'))->up();

        $this->assertSame($before, DB::table('service_areas')->count());
    }
}
