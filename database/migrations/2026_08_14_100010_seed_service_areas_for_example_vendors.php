<?php

declare(strict_types=1);

use App\Support\ExampleData\VendorListingExampleData;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Seeds the example `service_areas` rows per vendor — the checkout's area
 * selector depends on them (verified live on dev: with zero rows the select
 * renders empty and checkout can never validate). The vendors themselves are
 * seeded by `2026_08_14_100000_seed_vendors_and_listings.php`; this migration
 * runs after it and adds each vendor's delivery areas.
 *
 * This is the every-environment path: nothing in CI or any deployment runs
 * `php artisan db:seed`, so example content that must exist in every
 * environment ships through `php artisan migrate` (same convention as the
 * vendor/listing seed).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Guards on "areas already exist" (not on vendor existence), so this
        // runs correctly AFTER the vendor/listing seed on fresh environments
        // and is a safe no-op on re-runs.
        VendorListingExampleData::seedServiceAreas();
    }

    public function down(): void
    {
        $vendorNames = array_column(VendorListingExampleData::vendors(), 0);

        $vendorIds = DB::table('vendors')->whereIn('name', $vendorNames)->pluck('id');

        DB::table('service_areas')->whereIn('vendor_id', $vendorIds)->delete();
    }
};
