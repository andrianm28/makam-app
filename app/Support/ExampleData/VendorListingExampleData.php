<?php

declare(strict_types=1);

namespace App\Support\ExampleData;

use App\Domain\Marketplace\AvailabilityMode;
use App\Domain\Marketplace\EvidenceRequirement;
use App\Domain\Marketplace\ProductCode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The ONE place the EXAMPLE marketplace vendors and their per-product
 * listings are generated for the `vendors` and `vendor_listings` tables.
 * The data migration `2026_08_14_100000_seed_vendors_and_listings.php`
 * materializes from here, so a name/price/mode change is a single edit in
 * one file — never interlocking literal copies. Same procedural shape as
 * `VendorExampleData` (which owns the `products` catalogue columns) and
 * `CemeteryExampleData`.
 *
 * ---------------------------------------------------------------------------
 * DETERMINISM AND HONESTY — read this before changing any rule below
 * ---------------------------------------------------------------------------
 * - Vendor names are GENERATED positionally ("Toko Bunga Contoh 1"..
 *   "Toko Bunga Contoh 5") with the repository's established fabricated-data
 *   marker "Contoh" (Indonesian for "Example") — the same marker as
 *   "Jl. Contoh ..." in `CemeteryExampleData` and the dummy grave-record
 *   names. No name here is or reads like a real business; "Toko Bunga"
 *   (florist) is a generic trade, and the marker makes the fixture
 *   unmistakable at a glance.
 * - Every listing value derives purely from its indices over the closed
 *   lists (`ProductCode::KNOWN_CODES`, `AvailabilityMode::KNOWN`,
 *   `EvidenceRequirement::KNOWN`) plus `VendorExampleData::basePrice()`.
 *   No `random()`, no `time()`: nine listings, five vendors, byte-identical
 *   in every environment and every run.
 * - Prices are the catalogue's own example base price in MINOR units
 *   (`basePrice($code) * 100`), so the listing offer tracks the vendor
 *   generator's pricing rule instead of inventing a second one.
 * - `stock_quantity` is `null` whenever the availability mode is not
 *   `STOCKED`, matching the Postgres CHECK constraint
 *   `vendor_listings_stock_only_when_stocked` — a seeded row must be
 *   insertable on every engine the suite runs on, not just SQLite.
 *
 * ---------------------------------------------------------------------------
 * Why five vendors for nine listings
 * ---------------------------------------------------------------------------
 * The MVP allows one vendor per checkout but not one vendor per product:
 * competing offers are expected to exist, and the deterministic pick in
 * `App\Livewire\Public\Marketplace\ProductDetail::firstActiveListing()`
 * (creation order) is exactly what `ProductDetail` documents as the honest
 * MVP reading until a real vendor selector exists. Five vendors cycled over
 * nine listings gives every listing a vendor, keeps the vendor count small
 * enough to read as fixtures, and makes the one-vendor-per-checkout
 * conflict reachable from seeded data alone (products whose listings sit
 * under different vendors) — `VendorListingBootstrapTest` proves that flow.
 */
final class VendorListingExampleData
{
    /** How many example vendors exist. Deterministic by construction. */
    private const int VENDOR_COUNT = 5;

    /**
     * Shape: [name, is_active] — five synthetic vendors. All five are
     * active: every seeded listing is a live offer, and an inactive vendor
     * holding active listings would be an inconsistency no fixture needs.
     *
     * @return list<array{0: string, 1: bool}>
     */
    public static function vendors(): array
    {
        $rows = [];

        for ($i = 0; $i < self::VENDOR_COUNT; $i++) {
            $rows[] = [sprintf('Toko Bunga Contoh %d', $i + 1), true];
        }

        return $rows;
    }

    /**
     * Shape: [product_code_index, vendor_index, price_minor, availability_mode,
     *         stock_quantity, production_lead_time_days, cancellation_policy,
     *         evidence_requirement]
     * — one row per `ProductCode::KNOWN_CODES` entry, in catalog order.
     *
     * Index rules:
     * - `vendor_index` = `product_code_index % 5`, so listing i belongs to
     *   vendor i % 5 and different products end up under different vendors
     *   (conflict flow reachable from seed alone).
     * - `price_minor` = `VendorExampleData::basePrice($code) * 100`.
     * - `availability_mode`/`evidence_requirement` cycle their closed lists
     *   by index (`KNOWN[$i % 3]`).
     * - `stock_quantity` is a small deterministic count on `STOCKED` rows
     *   only, else `null` (Postgres CHECK, class doc block).
     * - `production_lead_time_days` is a small deterministic 1-14 day count.
     * - `cancellation_policy` is a short honest "contoh" sentence carrying
     *   the example marker, never a claim about real terms.
     *
     * @return list<array{0: int, 1: int, 2: int, 3: string, 4: ?int, 5: int, 6: string, 7: string}>
     */
    public static function listings(): array
    {
        $rows = [];

        foreach (array_values(ProductCode::KNOWN_CODES) as $i => $code) {
            $mode = AvailabilityMode::KNOWN[$i % 3];

            $rows[] = [
                $i,
                $i % self::VENDOR_COUNT,
                VendorExampleData::basePrice($code) * 100,
                $mode,
                $mode === AvailabilityMode::STOCKED ? 4 + (($i * 3) % 17) : null,
                1 + (($i * 5) % 14),
                sprintf(
                    'Contoh kebijakan pembatalan: penawaran contoh ini dapat dibatalkan maksimal 24 jam sebelum pengerjaan dimulai. (listing contoh %d)',
                    $i + 1
                ),
                EvidenceRequirement::KNOWN[$i % 3],
            ];
        }

        return $rows;
    }

    /**
     * Creates the five example vendors (uuid ids via `Str::uuid()`) and the
     * nine listings, resolving product ids from `products.code`. Idempotent:
     * a database that already carries any of the synthetic vendor names is
     * left untouched — migrations run once, but the guard makes re-runs
     * (fresh installs, partial rollbacks, manual invocation) safe.
     */
    /**
     * Delivery areas per example vendor — the `service_areas` rows the
     * checkout's area selector requires (verified live on dev: with zero
     * rows, the select renders empty and checkout can never validate).
     * Deterministic: each vendor serves 3 Jakarta-region example areas with
     * a delivery fee derived from the vendor index, so the checkout can
     * always complete in a fresh environment.
     *
     * Shape: [vendor_index, area_code, area_label, delivery_fee_minor].
     *
     * @return list<array{0: int, 1: string, 2: string, 3: int}>
     */
    public static function serviceAreas(): array
    {
        $areas = [];

        foreach (range(0, self::VENDOR_COUNT - 1) as $vendorIndex) {
            foreach ([
                ['EX-JKT-01', 'Jakarta Pusat', 0],
                ['EX-JKT-02', 'Jakarta Selatan', 150_000],
                ['EX-JKT-03', 'Jakarta Timur', 200_000],
            ] as [$code, $label, $fee]) {
                $areas[] = [$vendorIndex, $code, $label, $fee + ($vendorIndex * 25_000)];
            }
        }

        return $areas;
    }

    public static function seed(): void
    {
        $vendors = self::vendors();
        $names = array_column($vendors, 0);

        if (DB::table('vendors')->whereIn('name', $names)->exists()) {
            return;
        }

        $now = now();
        $vendorIds = [];

        foreach ($vendors as [$name, $isActive]) {
            $id = (string) Str::uuid();
            $vendorIds[$name] = $id;

            DB::table('vendors')->insert([
                'id' => $id,
                'name' => $name,
                'is_active' => $isActive,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $productIds = DB::table('products')
            ->whereIn('code', array_values(ProductCode::KNOWN_CODES))
            ->pluck('id', 'code');

        foreach (self::listings() as [$productIndex, $vendorIndex, $priceMinor, $mode, $stock, $leadTime, $policy, $evidence]) {
            $code = ProductCode::KNOWN_CODES[$productIndex];
            $productId = $productIds[$code] ?? null;

            // A code this data expects but cannot find means the catalogue
            // seed was rolled back or edited. Skip rather than fail: a
            // missing FIXTURE row must never block a real deployment's
            // migration run. (Same choice `CemeteryExampleData::
            // seedGraveRecords()` documents.)
            if ($productId === null) {
                continue;
            }

            DB::table('vendor_listings')->insert([
                'vendor_id' => $vendorIds[$vendors[$vendorIndex][0]],
                'product_id' => $productId,
                'price_minor' => $priceMinor,
                'price_version' => 1,
                'availability_mode' => $mode,
                'stock_quantity' => $stock,
                'production_lead_time_days' => $leadTime,
                'cancellation_policy' => $policy,
                'evidence_requirement' => $evidence,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        foreach (self::serviceAreas() as [$vendorIndex, $areaCode, $areaLabel, $deliveryFeeMinor]) {
            $vendorName = $vendors[$vendorIndex][0];

            DB::table('service_areas')->insert([
                'vendor_id' => $vendorIds[$vendorName],
                'area_code' => $areaCode,
                'area_label' => $areaLabel,
                'delivery_fee_minor' => $deliveryFeeMinor,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /**
     * Seeds ONLY the example service areas for the example vendors.
     *
     * Separate from `seed()` (which guards on vendor existence and therefore
     * returns early on environments where the vendor/listing migration already
     * ran): the service-areas migration runs AFTER the vendor seed on fresh
     * environments, so it must be able to add areas without re-running the
     * vendor-creation half. Guarded on "areas already exist for the first
     * vendor" so a re-run cannot hit the unique (vendor_id, area_code)
     * constraint.
     */
    public static function seedServiceAreas(): void
    {
        $vendors = self::vendors();
        $firstVendorName = $vendors[0][0];

        $firstVendorId = DB::table('vendors')->where('name', $firstVendorName)->value('id');

        if ($firstVendorId === null) {
            // The vendor seed has not run; nothing to attach areas to.
            return;
        }

        if (DB::table('service_areas')->where('vendor_id', $firstVendorId)->exists()) {
            return;
        }

        $now = now();
        $vendorIds = DB::table('vendors')
            ->whereIn('name', array_column($vendors, 0))
            ->pluck('id', 'name');

        foreach (self::serviceAreas() as [$vendorIndex, $areaCode, $areaLabel, $deliveryFeeMinor]) {
            $vendorName = $vendors[$vendorIndex][0];
            $vendorId = $vendorIds[$vendorName] ?? null;

            if ($vendorId === null) {
                continue;
            }

            DB::table('service_areas')->insert([
                'vendor_id' => $vendorId,
                'area_code' => $areaCode,
                'area_label' => $areaLabel,
                'delivery_fee_minor' => $deliveryFeeMinor,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
}
