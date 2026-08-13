<?php

declare(strict_types=1);

namespace Tests\Unit\Support\ExampleData;

use App\Domain\Marketplace\ProductCode;
use App\Support\ExampleData\VendorExampleData;
use PHPUnit\Framework\TestCase;

/**
 * Locks the vendor generator's INTERNAL consistency: synthetic names,
 * deterministic per-code pricing in round 500k increments. The DB-facing
 * contract (nine products updated with the generated rows) is asserted by
 * the migration/seed feature tests.
 */
final class VendorExampleDataTest extends TestCase
{
    public function test_vendor_names_are_synthetic_and_deterministic(): void
    {
        $names = array_column(VendorExampleData::vendors(), 1);

        $this->assertSame(count(ProductCode::KNOWN_CODES), count($names));
        foreach ($names as $name) {
            $this->assertMatchesRegularExpression('/^(UD|CV) [A-Z][a-z]+ [A-Z][a-z]+$/', $name);
        }
        // Deterministic: same call twice → same names
        $this->assertSame($names, array_column(VendorExampleData::vendors(), 1));
    }

    public function test_base_price_is_a_deterministic_formula(): void
    {
        $first = VendorExampleData::basePrice(ProductCode::FLOWER_BOARD);
        $again = VendorExampleData::basePrice(ProductCode::FLOWER_BOARD);
        $this->assertSame($first, $again);
        $this->assertGreaterThan(0, $first);
        $this->assertSame(0, $first % 500_000, 'Prices should be round increments of 500k.');
    }

    public function test_vendors_cover_every_product_code_exactly_once(): void
    {
        $codes = array_column(VendorExampleData::vendors(), 0);
        $this->assertSame(array_values(ProductCode::KNOWN_CODES), $codes);
    }

    public function test_vendor_names_are_unique(): void
    {
        $names = array_column(VendorExampleData::vendors(), 1);
        $this->assertSame(count($names), count(array_unique($names)), 'Vendor names must not collide between product rows.');
    }
}
