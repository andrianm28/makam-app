<?php

declare(strict_types=1);

use App\Support\ExampleData\RealisticMarketplacePricingExampleData;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ===========================================================================
 * THIS IS DUMMY / PLACEHOLDER DATA. NONE OF THE FOLLOWING IS REAL.
 * ===========================================================================
 * Every vendor below is FICTIONAL — the "Contoh" (Example) marker is on
 * every name, the same fabricated-data convention as
 * `VendorListingExampleData`'s "Toko Bunga Contoh N" vendors, "Jl. Contoh
 * ..." in the cemetery seed, and the dummy grave-record names. No vendor
 * name here is or reads like a real business.
 *
 * ---------------------------------------------------------------------------
 * Why this exists alongside the already-shipped `VendorListingExampleData`
 * ---------------------------------------------------------------------------
 * That migration's prices are a deterministic hash with no relationship to
 * what these products actually cost (`VendorExampleData::basePrice()`'s own
 * doc block: "a stable per-code hash folded into 500k increments") — fine
 * for proving checkout mechanics, not representative for a beta review of
 * what the marketplace looks like with plausible pricing. This migration
 * seeds a SECOND, separate set of fictional vendors/listings for the SAME
 * nine products, priced from real-world researched ranges instead (Jakarta
 * florist/monument/grave-care listings, researched 25 Aug 2026) — see
 * `App\Support\ExampleData\RealisticMarketplacePricingExampleData`'s own doc
 * block for every price point and the reasoning/range behind it.
 *
 * Both fixtures can coexist: `vendor_listings_offer_unique` is scoped to
 * `(vendor_id, product_id)`, and these are entirely new vendor rows, so
 * `ProductDetail::firstActiveListing()`'s creation-order pick simply gains
 * more real offers per product rather than colliding with the existing ones.
 *
 * ---------------------------------------------------------------------------
 * Gated behind `config('example_data.seed_realistic_marketplace_pricing')`,
 * default false, PLUS an independent `app()->isProduction()` guard
 * ---------------------------------------------------------------------------
 * Unlike `2026_08_14_100000_seed_vendors_and_listings.php` (unconditional,
 * because it exists to make the marketplace checkout journey minimally
 * operable everywhere, dev included), this migration is NOT meant to run
 * everywhere by default — it is an explicit, opt-in decision for a specific
 * environment (e.g. a beta review), following the SAME gating shape
 * `2026_08_22_110000_seed_e2e_admin_vendor_test_users.php` and
 * `2026_08_23_120000_seed_external_renewal_fixture.php` already establish:
 * `RefreshDatabase` applies every migration once per PHPUnit process, so an
 * unconditional `up()` here would permanently write real `vendors`/
 * `vendor_listings`/`service_areas` rows into every unrelated Feature test's
 * database in the same process. The second, independent
 * `app()->isProduction()` guard is defence-in-depth: even if the config flag
 * were ever mistakenly set true on a production deploy, this still refuses
 * to run there.
 *
 * NOT YET APPROVED to run against the live beta database as of this
 * migration's creation (25 Aug 2026) — a separate decision about whether
 * beta gets a visible demo/example UI marker for this data is still pending
 * with the project owner. This migration ships as reviewable, gated code
 * only; enabling `SEED_REALISTIC_MARKETPLACE_PRICING` on any real deployment
 * is a deliberate follow-up action, not something this PR does.
 *
 * ---------------------------------------------------------------------------
 * Why a data migration and not `database/seeders/`
 * ---------------------------------------------------------------------------
 * Nothing in CI, the Dockerfile, or any deployment script runs
 * `php artisan db:seed` — every fixture dataset in this repository ships as
 * a timestamped data migration instead (same reasoning as every other
 * fixture migration in this repository).
 *
 * ---------------------------------------------------------------------------
 * `down()` deletes exactly what `up()` inserted
 * ---------------------------------------------------------------------------
 * Same shape as `2026_08_14_100000_seed_vendors_and_listings.php`'s own
 * `down()`: listings and service areas deleted first (their FK to `vendors`
 * is restrict-on-delete), scoped to the three fixture vendor ids; vendors
 * then deleted by their generated names. Never a blanket truncate
 * (`AGENTS.md` §Database) — real vendor rows can never be caught by this
 * rollback even if one somehow shared a name.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! config('example_data.seed_realistic_marketplace_pricing')) {
            return;
        }

        if (app()->isProduction()) {
            return;
        }

        RealisticMarketplacePricingExampleData::seed();
    }

    public function down(): void
    {
        $names = array_column(RealisticMarketplacePricingExampleData::vendors(), 0);

        $vendorIds = DB::table('vendors')->whereIn('name', $names)->pluck('id');

        DB::table('service_areas')->whereIn('vendor_id', $vendorIds)->delete();
        DB::table('vendor_listings')->whereIn('vendor_id', $vendorIds)->delete();
        DB::table('vendors')->whereIn('name', $names)->delete();
    }
};
