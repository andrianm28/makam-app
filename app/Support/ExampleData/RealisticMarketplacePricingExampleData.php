<?php

declare(strict_types=1);

namespace App\Support\ExampleData;

use App\Domain\Marketplace\AvailabilityMode;
use App\Domain\Marketplace\EvidenceRequirement;
use App\Domain\Marketplace\ProductCode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A SECOND, separately-gated marketplace vendor/listing fixture, distinct
 * from `VendorListingExampleData`. That class's prices are a deterministic
 * `crc32`-derived hash with no relationship to what these products actually
 * cost (`VendorExampleData::basePrice()`'s own doc block: "a stable
 * per-code hash folded into 500k increments") — fine for proving the
 * checkout mechanics work, not representative of anything real. This class
 * exists to seed the SAME nine products with real-world-researched price
 * RANGES instead, for a beta review of what the marketplace looks like with
 * plausible pricing.
 *
 * ---------------------------------------------------------------------------
 * Fictional vendors, researched prices — the two are independent
 * ---------------------------------------------------------------------------
 * The vendor names are fictional, following this repository's established
 * "Contoh" (Example) marker convention exactly like `VendorListingExampleData`
 * (see that class's own doc block on why the marker exists) — no vendor here
 * is or reads like a real business. The PRICES, however, are not arbitrary:
 * each is a defensible point within a real range found via web research on
 * 25 Aug 2026 (Jakarta-area florist/monument/grave-care listings), recorded
 * per product below with its source reasoning. A researched price on a
 * fictional vendor is not a claim that the vendor is real — it is a claim
 * that the NUMBER is realistic, which is exactly what this fixture is for.
 *
 * Researched price points (IDR, per unit unless noted):
 * - FLOWER_BOARD (karangan bunga papan): 650_000. Real Jakarta florist
 *   listings (Tokopedia, Emma Florist, Prestisa) range roughly 300_000 to
 *   2_500_000 for a standard 2m x 1.2m board; 650_000 sits in the common
 *   mid-tier band multiple listings cluster around.
 * - FLOWER_PETAL_PACKAGE (bunga tabur): 150_000. Real listings range from
 *   15_000 (a small bag) to 300_000 (a full basket, "lengkap"); 150_000 is a
 *   mid-sized package, not the cheapest bag nor the largest basket.
 * - GRAVESTONE_GRANITE (batu nisan granit, plain/standard finish):
 *   2_500_000. Distinct from the custom-calligraphy tier below — granite
 *   monument stock without elaborate carving.
 * - GRAVESTONE_MARBLE (batu nisan marmer): 1_200_000. Real listings range
 *   roughly 520_000 to 2_550_000 for standard sizes (install/delivery
 *   excluded per those listings); 1_200_000 sits mid-range.
 * - GRAVESTONE_CALLIGRAPHY (batu nisan granit ukir kaligrafi, custom carved):
 *   7_500_000. Real custom-carved-calligraphy granite listings range
 *   roughly 5_000_000 to 15_000_000 depending on design complexity;
 *   7_500_000 is the low-to-mid end of that band, not the most elaborate
 *   tier.
 * - GRAVE_CARE_MONTHLY (perawatan makam bulanan): 150_000/month. Real
 *   family-paid monthly grave-care arrangements commonly run 100_000 to
 *   200_000/month.
 * - GRAVE_CARE_QUARTERLY: 400_000 (vs. 3 x 150_000 = 450_000 paid monthly —
 *   a modest pay-ahead discount, the same shape real subscription pricing
 *   usually takes).
 * - GRAVE_CARE_SEMIANNUAL: 750_000 (vs. 6 x 150_000 = 900_000).
 * - GRAVE_CARE_ANNUAL: 1_350_000 (vs. 12 x 150_000 = 1_800_000) — still
 *   within the researched 500_000-2_000_000/year real range for annual
 *   grave-care arrangements, at the higher end reflecting the discount
 *   structure above.
 *
 * ---------------------------------------------------------------------------
 * Three vendors, grouped by trade — not five cycled arbitrarily
 * ---------------------------------------------------------------------------
 * `VendorListingExampleData` cycles five interchangeable "Toko Bunga"
 * vendors across all nine products regardless of trade (a florist "selling"
 * a headstone). This fixture groups the three real trades a beta reviewer
 * would expect to see as separate businesses — a florist, a monument
 * mason, and a grave-care service — each carrying only the products that
 * trade would plausibly sell.
 */
final class RealisticMarketplacePricingExampleData
{
    private const string FLORIST_VENDOR = 'Toko Bunga Contoh Melati Sejahtera';

    private const string MASON_VENDOR = 'CV Batu Nisan Contoh Abadi Prima';

    private const string CARE_VENDOR = 'UD Perawatan Makam Contoh Damai Nusantara';

    /**
     * @return list<array{0: string, 1: bool}> Shape: [name, is_active].
     */
    public static function vendors(): array
    {
        return [
            [self::FLORIST_VENDOR, true],
            [self::MASON_VENDOR, true],
            [self::CARE_VENDOR, true],
        ];
    }

    /**
     * Shape: [product_code, vendor_name, price_minor, availability_mode,
     *         production_lead_time_days, cancellation_policy, evidence_requirement]
     * — one row per product, priced per the research recorded in the class
     * doc block. No `stock_quantity`: nothing here is an off-the-shelf
     * stocked item (flowers are arranged to order, monuments are carved to
     * order, grave care is a scheduled recurring service), so every row is
     * `MADE_TO_ORDER` or `SCHEDULED` and `stock_quantity` stays null,
     * satisfying `vendor_listings_stock_only_when_stocked` the same way
     * `VendorListingExampleData` does.
     *
     * @return list<array{0: string, 1: string, 2: int, 3: string, 4: int, 5: string, 6: string}>
     */
    public static function listings(): array
    {
        return [
            [ProductCode::FLOWER_BOARD, self::FLORIST_VENDOR, 650_000, AvailabilityMode::MADE_TO_ORDER, 1,
                'Contoh kebijakan pembatalan: dapat dibatalkan maksimal 6 jam sebelum pengiriman karangan bunga.', EvidenceRequirement::NONE],
            [ProductCode::FLOWER_PETAL_PACKAGE, self::FLORIST_VENDOR, 150_000, AvailabilityMode::MADE_TO_ORDER, 1,
                'Contoh kebijakan pembatalan: dapat dibatalkan maksimal 6 jam sebelum pengiriman.', EvidenceRequirement::NONE],
            [ProductCode::GRAVESTONE_GRANITE, self::MASON_VENDOR, 2_500_000, AvailabilityMode::MADE_TO_ORDER, 10,
                'Contoh kebijakan pembatalan: dapat dibatalkan sebelum proses pengerjaan batu dimulai; setelah dimulai dikenakan biaya material.', EvidenceRequirement::PHOTO],
            [ProductCode::GRAVESTONE_MARBLE, self::MASON_VENDOR, 1_200_000, AvailabilityMode::MADE_TO_ORDER, 10,
                'Contoh kebijakan pembatalan: dapat dibatalkan sebelum proses pengerjaan batu dimulai; setelah dimulai dikenakan biaya material.', EvidenceRequirement::PHOTO],
            [ProductCode::GRAVESTONE_CALLIGRAPHY, self::MASON_VENDOR, 7_500_000, AvailabilityMode::MADE_TO_ORDER, 14,
                'Contoh kebijakan pembatalan: dapat dibatalkan sebelum desain ukiran disetujui; setelah disetujui dikenakan biaya desain dan material.', EvidenceRequirement::PHOTO],
            [ProductCode::GRAVE_CARE_MONTHLY, self::CARE_VENDOR, 150_000, AvailabilityMode::SCHEDULED, 2,
                'Contoh kebijakan pembatalan: dapat dibatalkan kapan pun sebelum kunjungan bulan berjalan dijadwalkan.', EvidenceRequirement::PHOTO],
            [ProductCode::GRAVE_CARE_QUARTERLY, self::CARE_VENDOR, 400_000, AvailabilityMode::SCHEDULED, 2,
                'Contoh kebijakan pembatalan: dapat dibatalkan kapan pun sebelum kunjungan pertama dijadwalkan.', EvidenceRequirement::PHOTO],
            [ProductCode::GRAVE_CARE_SEMIANNUAL, self::CARE_VENDOR, 750_000, AvailabilityMode::SCHEDULED, 2,
                'Contoh kebijakan pembatalan: dapat dibatalkan kapan pun sebelum kunjungan pertama dijadwalkan.', EvidenceRequirement::PHOTO],
            [ProductCode::GRAVE_CARE_ANNUAL, self::CARE_VENDOR, 1_350_000, AvailabilityMode::SCHEDULED, 2,
                'Contoh kebijakan pembatalan: dapat dibatalkan kapan pun sebelum kunjungan pertama dijadwalkan.', EvidenceRequirement::PHOTO],
        ];
    }

    /**
     * Delivery/service areas per vendor — required for the same reason
     * `VendorListingExampleData::serviceAreas()` documents (a vendor with
     * zero `service_areas` rows can never complete checkout, verified live
     * on dev by that class). Three Jakarta-region areas per vendor, a flat
     * delivery fee per vendor (florist delivers per-order, so a real fee;
     * the mason and care vendor's fee is nominal since their "delivery" is
     * really a site visit already priced into the listing).
     *
     * Shape: [vendor_name, area_code, area_label, delivery_fee_minor].
     *
     * @return list<array{0: string, 1: string, 2: string, 3: int}>
     */
    public static function serviceAreas(): array
    {
        $areas = [];

        foreach ([
            [self::FLORIST_VENDOR, 100_000],
            [self::MASON_VENDOR, 0],
            [self::CARE_VENDOR, 0],
        ] as [$vendorName, $baseFee]) {
            foreach ([
                ['RP-JKT-01', 'Jakarta Pusat'],
                ['RP-JKT-02', 'Jakarta Selatan'],
                ['RP-JKT-03', 'Jakarta Timur'],
            ] as $i => [$code, $label]) {
                $areas[] = [$vendorName, $code, $label, $baseFee + ($i * 25_000)];
            }
        }

        return $areas;
    }

    /**
     * Idempotent on the same shape `VendorListingExampleData::seed()` uses:
     * a database that already carries any of these three vendor names is
     * left untouched.
     */
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

        foreach (self::listings() as [$code, $vendorName, $priceMinor, $mode, $leadTime, $policy, $evidence]) {
            $productId = $productIds[$code] ?? null;

            // Same fail-open-on-missing-code guard `VendorListingExampleData`
            // documents: a fixture row must never block a real deployment's
            // migration run if the catalogue seed changed shape.
            if ($productId === null) {
                continue;
            }

            DB::table('vendor_listings')->insert([
                'vendor_id' => $vendorIds[$vendorName],
                'product_id' => $productId,
                'price_minor' => $priceMinor,
                'price_version' => 1,
                'availability_mode' => $mode,
                'stock_quantity' => null,
                'production_lead_time_days' => $leadTime,
                'cancellation_policy' => $policy,
                'evidence_requirement' => $evidence,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        foreach (self::serviceAreas() as [$vendorName, $areaCode, $areaLabel, $deliveryFeeMinor]) {
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
