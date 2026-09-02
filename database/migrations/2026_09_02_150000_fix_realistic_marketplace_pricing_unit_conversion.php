<?php

declare(strict_types=1);

use App\Domain\Marketplace\ProductCode;
use App\Support\ExampleData\RealisticMarketplacePricingExampleData;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Corrects the rupiah/minor-unit conversion bug in
 * `RealisticMarketplacePricingExampleData` (see that class's own "Unit bug
 * found in UAT" doc block), found live on beta 2 Sep 2026: `seed()`
 * originally inserted the class's plain-rupiah price/delivery-fee figures
 * directly into `vendor_listings.price_minor`/`service_areas.delivery_fee_
 * minor` without the x100 conversion those columns require, making every
 * price and delivery fee this fixture ever seeded 100x too low (e.g.
 * "Karangan Bunga Papan" showed Rp 6.500 instead of the intended Rp
 * 650.000).
 *
 * `RealisticMarketplacePricingExampleData::seed()` itself is fixed in this
 * same change (converts correctly now), so any environment that seeds
 * fresh AFTER this migration is unaffected. This migration exists only to
 * repair rows an environment already seeded with the buggy version, before
 * this fix landed — `2026_08_25_140000_seed_realistic_marketplace_pricing_
 * fixtures` already ran (with the feature flag on) against the live beta
 * database, so its rows are exactly the ones that need correcting here.
 *
 * ---------------------------------------------------------------------------
 * Why this is safe to run unconditionally (no `app()->isProduction()` guard)
 * ---------------------------------------------------------------------------
 * Every other fixture migration in this repository refuses to run in
 * production because it INSERTS new fictional rows — the risk is polluting
 * a real database with fake vendors. This migration does the opposite: it
 * only ever UPDATES rows that already exist, scoped to the three exact,
 * unmistakably-fictional "Contoh"-marked vendor names
 * `RealisticMarketplacePricingExampleData::vendors()` defines, matched
 * against the SAME product codes / area codes that fixture seeds. A real
 * production database — one that never ran the gated seed migration with
 * `SEED_REALISTIC_MARKETPLACE_PRICING=true` — has no vendor rows with these
 * names, so this migration's `up()` finds nothing to correct there and is a
 * true no-op. It cannot invent data or touch anything real.
 *
 * ---------------------------------------------------------------------------
 * Idempotent and safe to run more than once, or against a partially-fixed
 * database
 * ---------------------------------------------------------------------------
 * Beta's `vendor_listings.price_minor` rows for these three vendors were
 * already hand-corrected (via a live, direct database write made during
 * this same UAT round, before this migration existed) to the exact values
 * this migration would also produce — but `service_areas.delivery_fee_
 * minor` was NOT touched by that earlier fix and is still wrong. Rather
 * than branch on "was this already fixed," `up()` unconditionally SETS
 * every matched row to the correct, freshly-computed value (`price_idr *
 * 100` / `delivery_fee_idr * 100`, straight from
 * `RealisticMarketplacePricingExampleData::listings()`/`serviceAreas()`).
 * Setting an already-correct row to the same correct value is a no-op in
 * effect; setting a still-wrong row corrects it. Re-running this migration
 * (e.g. `migrate:refresh` in a test) produces the identical end state every
 * time.
 *
 * ---------------------------------------------------------------------------
 * `down()`
 * ---------------------------------------------------------------------------
 * No meaningful inverse: reverting a data correction back to a known-wrong
 * value serves no purpose and there is no other "previous correct state" to
 * restore. Intentionally a no-op, not a revert-to-buggy-values operation.
 */
return new class extends Migration
{
    public function up(): void
    {
        $vendorIds = DB::table('vendors')
            ->whereIn('name', array_column(RealisticMarketplacePricingExampleData::vendors(), 0))
            ->pluck('id', 'name');

        if ($vendorIds->isEmpty()) {
            return;
        }

        $productIds = DB::table('products')
            ->whereIn('code', array_values(ProductCode::KNOWN_CODES))
            ->pluck('id', 'code');

        foreach (RealisticMarketplacePricingExampleData::listings() as [$code, $vendorName, $priceIdr]) {
            $vendorId = $vendorIds[$vendorName] ?? null;
            $productId = $productIds[$code] ?? null;

            if ($vendorId === null || $productId === null) {
                continue;
            }

            DB::table('vendor_listings')
                ->where('vendor_id', $vendorId)
                ->where('product_id', $productId)
                ->update(['price_minor' => $priceIdr * 100]);
        }

        foreach (RealisticMarketplacePricingExampleData::serviceAreas() as [$vendorName, $areaCode, , $deliveryFeeIdr]) {
            $vendorId = $vendorIds[$vendorName] ?? null;

            if ($vendorId === null) {
                continue;
            }

            DB::table('service_areas')
                ->where('vendor_id', $vendorId)
                ->where('area_code', $areaCode)
                ->update(['delivery_fee_minor' => $deliveryFeeIdr * 100]);
        }
    }

    public function down(): void
    {
        // Intentionally a no-op — see class doc block.
    }
};
