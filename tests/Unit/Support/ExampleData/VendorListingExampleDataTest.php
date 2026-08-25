<?php

declare(strict_types=1);

namespace Tests\Unit\Support\ExampleData;

use App\Domain\Marketplace\AvailabilityMode;
use App\Domain\Marketplace\EvidenceRequirement;
use App\Domain\Marketplace\ProductCode;
use App\Support\ExampleData\VendorExampleData;
use App\Support\ExampleData\VendorListingExampleData;
use PHPUnit\Framework\TestCase;

/**
 * Locks the vendor-listing generator's INTERNAL consistency: five synthetic
 * vendors, one listing per product code, deterministic index-derived values
 * (price in minor units from `VendorExampleData::basePrice()`), and only
 * known closed-list values. The DB-facing contract (five vendors and nine
 * listings in every fresh database) is asserted by the migration feature
 * test `VendorListingBootstrapTest`.
 */
final class VendorListingExampleDataTest extends TestCase
{
    public function test_vendors_are_five_synthetic_names_and_deterministic(): void
    {
        $names = array_column(VendorListingExampleData::vendors(), 0);

        $this->assertCount(5, $names);
        $this->assertSame(count($names), count(array_unique($names)), 'Vendor names must not collide.');
        foreach ($names as $name) {
            $this->assertMatchesRegularExpression('/^Toko Bunga Contoh \d+$/', $name);
        }
        // Deterministic: same call twice → same names.
        $this->assertSame($names, array_column(VendorListingExampleData::vendors(), 0));
    }

    public function test_there_is_exactly_one_listing_per_product_code_in_catalog_order(): void
    {
        $rows = VendorListingExampleData::listings();

        $this->assertCount(count(ProductCode::KNOWN_CODES), $rows);

        // One row per code, in catalog order: the product_code_index column
        // is exactly 0..8, each used once.
        $indices = array_column($rows, 0);
        $this->assertSame(range(0, count(ProductCode::KNOWN_CODES) - 1), $indices);
    }

    public function test_every_listing_price_is_the_base_price_in_minor_units(): void
    {
        foreach (VendorListingExampleData::listings() as [$productIndex, , $priceMinor]) {
            $code = ProductCode::KNOWN_CODES[$productIndex];

            $this->assertSame(
                VendorExampleData::basePrice($code) * 100,
                $priceMinor,
                "Listing [{$code}] price must be VendorExampleData::basePrice() in minor units."
            );
        }
    }

    public function test_every_listing_carries_only_known_closed_list_values(): void
    {
        foreach (VendorListingExampleData::listings() as [$productIndex, , , $mode, $stock, $leadTime, $policy, $evidence]) {
            $this->assertTrue(
                AvailabilityMode::isKnown($mode),
                "Listing [{$productIndex}] has an unknown availability mode."
            );
            $this->assertTrue(
                EvidenceRequirement::isKnown($evidence),
                "Listing [{$productIndex}] has an unknown evidence requirement."
            );

            // The Postgres seed schema forbids stock_quantity on non-STOCKED
            // rows (vendor_listings_stock_only_when_stocked); SQLite does not
            // enforce the CHECK, so the generator itself must never emit it.
            if ($mode !== AvailabilityMode::STOCKED) {
                $this->assertNull(
                    $stock,
                    "Listing [{$productIndex}] must have NULL stock unless STOCKED."
                );
            }

            $this->assertGreaterThan(0, $leadTime, 'Lead time must be a positive deterministic day count.');
            $this->assertStringContainsString('Contoh', $policy, 'Cancellation policy must carry the example marker.');
        }
    }

    public function test_vendor_indices_resolve_inside_the_vendor_list(): void
    {
        $vendorCount = count(VendorListingExampleData::vendors());

        foreach (VendorListingExampleData::listings() as [$productIndex, $vendorIndex]) {
            $this->assertGreaterThanOrEqual(0, $vendorIndex);
            $this->assertLessThan($vendorCount, $vendorIndex);
            $this->assertArrayHasKey($productIndex, ProductCode::KNOWN_CODES);
        }
    }

    public function test_listings_are_deterministic(): void
    {
        $this->assertSame(VendorListingExampleData::listings(), VendorListingExampleData::listings());
    }

    public function test_service_areas_are_deterministic_and_cover_every_vendor(): void
    {
        $areas = VendorListingExampleData::serviceAreas();

        $this->assertSame(15, count($areas));
        $this->assertSame($areas, VendorListingExampleData::serviceAreas());

        foreach (range(0, 4) as $vendorIndex) {
            $vendorAreas = array_filter(
                $areas,
                static fn (array $a): bool => $a[0] === $vendorIndex,
            );
            $this->assertCount(3, $vendorAreas, "Vendor [{$vendorIndex}] must have 3 service areas.");
        }
    }
}
