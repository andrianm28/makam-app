<?php

declare(strict_types=1);

namespace Tests\Feature\Migrations;

use App\Domain\Marketplace\ProductCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Covers `2026_09_02_150000_fix_realistic_marketplace_pricing_unit_
 * conversion.php` — see that migration's own doc block for the bug and why
 * an unconditional, UPDATE-only migration matched by fictional vendor name
 * is safe with no `app()->isProduction()` guard. Runs `up()` directly, same
 * pattern as `SeedRealisticMarketplacePricingFixturesTest` (this migration
 * is not itself gated, so it DOES run during the ambient `setUp()` migrate
 * — these tests seed their own known-wrong rows first to prove the fix,
 * independent of whatever the ambient migrate already produced).
 */
final class FixRealisticMarketplacePricingUnitConversionTest extends TestCase
{
    use RefreshDatabase;

    private const string MIGRATION_PATH = 'migrations/2026_09_02_150000_fix_realistic_marketplace_pricing_unit_conversion.php';

    private const string FLORIST_VENDOR = 'Toko Bunga Contoh Melati Sejahtera';

    public function test_it_corrects_a_still_buggy_price_to_minor_units(): void
    {
        $vendorId = $this->seedFloristVendor();
        $productId = DB::table('products')->where('code', ProductCode::FLOWER_BOARD)->value('id');
        $this->assertNotNull($productId);

        DB::table('vendor_listings')->insert([
            'vendor_id' => $vendorId,
            'product_id' => $productId,
            'price_minor' => 650_000, // the pre-fix bug: raw rupiah, not x100
            'price_version' => 1,
            'availability_mode' => 'MADE_TO_ORDER',
            'production_lead_time_days' => 1,
            'cancellation_policy' => 'test',
            'evidence_requirement' => 'NONE',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        (require database_path(self::MIGRATION_PATH))->up();

        $this->assertDatabaseHas('vendor_listings', [
            'vendor_id' => $vendorId,
            'product_id' => $productId,
            'price_minor' => 650_000 * 100,
        ]);
    }

    public function test_it_leaves_an_already_correct_price_unchanged(): void
    {
        $vendorId = $this->seedFloristVendor();
        $productId = DB::table('products')->where('code', ProductCode::FLOWER_BOARD)->value('id');
        $this->assertNotNull($productId);

        DB::table('vendor_listings')->insert([
            'vendor_id' => $vendorId,
            'product_id' => $productId,
            'price_minor' => 650_000 * 100, // already correct, e.g. hand-fixed earlier
            'price_version' => 1,
            'availability_mode' => 'MADE_TO_ORDER',
            'production_lead_time_days' => 1,
            'cancellation_policy' => 'test',
            'evidence_requirement' => 'NONE',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        (require database_path(self::MIGRATION_PATH))->up();

        $this->assertDatabaseHas('vendor_listings', [
            'vendor_id' => $vendorId,
            'product_id' => $productId,
            'price_minor' => 650_000 * 100,
        ]);
    }

    public function test_it_corrects_a_still_buggy_delivery_fee_to_minor_units(): void
    {
        $vendorId = $this->seedFloristVendor();

        DB::table('service_areas')->insert([
            'vendor_id' => $vendorId,
            'area_code' => 'RP-JKT-01',
            'area_label' => 'Jakarta Pusat',
            'delivery_fee_minor' => 100_000, // the pre-fix bug: raw rupiah, not x100
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        (require database_path(self::MIGRATION_PATH))->up();

        $this->assertDatabaseHas('service_areas', [
            'vendor_id' => $vendorId,
            'area_code' => 'RP-JKT-01',
            'delivery_fee_minor' => 100_000 * 100,
        ]);
    }

    public function test_it_does_nothing_when_none_of_the_three_fixture_vendors_exist(): void
    {
        $this->assertDatabaseMissing('vendors', ['name' => self::FLORIST_VENDOR]);

        // Must not throw or touch anything when the fixture was never seeded
        // (e.g. a real production database, per the migration's own doc
        // block on why it needs no isProduction() guard).
        (require database_path(self::MIGRATION_PATH))->up();

        $this->assertDatabaseMissing('vendors', ['name' => self::FLORIST_VENDOR]);
    }

    private function seedFloristVendor(): string
    {
        $id = (string) Str::uuid();

        DB::table('vendors')->insert([
            'id' => $id,
            'name' => self::FLORIST_VENDOR,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }
}
