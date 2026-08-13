<?php

declare(strict_types=1);

namespace Tests\Unit\Support\ExampleData;

use App\Domain\ServiceCatalog\FulfillmentOwner;
use App\Domain\ServiceCatalog\ServiceCode;
use App\Support\ExampleData\ServiceOperationalExampleData;
use PHPUnit\Framework\TestCase;

/**
 * Locks the service operational example-data generator's INTERNAL
 * consistency: the semantic fulfilment rule map covers every catalogue
 * code with known owners and boolean flags, and the dummy prices are
 * deterministic decimal strings sourced as example data. The DB-facing
 * contract (updating `service_definitions` + inserting `price_versions`
 * rows) is asserted by the migration/seed feature tests.
 */
final class ServiceOperationalExampleDataTest extends TestCase
{
    public function test_dummy_prices_are_deterministic_and_non_literal(): void
    {
        $prices = ServiceOperationalExampleData::dummyPrices();

        $this->assertSame(count(ServiceCode::KNOWN_CODES), count($prices));
        foreach ($prices as $code => [$amount, $source]) {
            $this->assertMatchesRegularExpression('/^\d+\.\d{2}$/', $amount, 'Amount must be a decimal string with 2 places.');
            $this->assertStringContainsString('data contoh', $source);
        }
        // Deterministic
        $this->assertSame($prices, ServiceOperationalExampleData::dummyPrices());
    }

    public function test_operational_defaults_cover_every_service(): void
    {
        $defaults = ServiceOperationalExampleData::operationalDefaults();
        foreach (ServiceCode::KNOWN_CODES as $code) {
            $this->assertArrayHasKey($code, $defaults);
        }
        foreach ($defaults as $code => [$owner, $schedule, $manual]) {
            $this->assertTrue(FulfillmentOwner::isKnown($owner));
            $this->assertIsBool($schedule);
            $this->assertIsBool($manual);
        }
    }

    public function test_dummy_prices_cover_every_service_code(): void
    {
        $this->assertSame(
            array_values(ServiceCode::KNOWN_CODES),
            array_keys(ServiceOperationalExampleData::dummyPrices()),
        );
    }
}
