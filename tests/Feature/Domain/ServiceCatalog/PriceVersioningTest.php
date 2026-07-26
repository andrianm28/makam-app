<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\ServiceCatalog;

use App\Domain\ServiceCatalog\Actions\RecordServiceDefinitionPriceVersion;
use App\Domain\ServiceCatalog\Models\PriceVersion;
use App\Domain\ServiceCatalog\Models\ServiceDefinition;
use App\Domain\ServiceCatalog\ServiceCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Proves the `price_versions` versioning mechanism `service-catalog.md`
 * "Catalog rules" requires ("Price is versioned and snapshot into
 * quote/order") against a real `service_definitions` row. Uses synthetic
 * test-only Rupiah amounts, never real catalogue pricing.
 *
 * `2026_07_26_180400_create_price_versions_table.php`'s own doc block
 * originally explained why NO price was seeded at all. That stopped being
 * true once
 * `2026_07_26_220000_seed_service_definition_dummy_operational_data.php`
 * landed as an explicitly-authorized follow-up: every one of the 12
 * `service_definitions` rows now starts with exactly one DUMMY/placeholder
 * `price_versions` row out of the box (see that migration's own doc block
 * — "not real catalogue pricing" still applies to those seeded amounts too,
 * same as this file's own synthetic ones). Because `RefreshDatabase` runs
 * every migration — including that one — before each test method here,
 * several tests below now explicitly clear a service's pre-existing seeded
 * price first, so they can still isolate "recording a version from a clean
 * slate" the way they always have, independent of that seeded baseline.
 */
final class PriceVersioningTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Removes whatever `price_versions` row(s) the dev-data migration
     * already recorded for `$service`, so a test can exercise "recording
     * this service's very first price version" from a genuinely empty
     * history, the same scenario these tests exercised before that
     * migration existed.
     */
    private function clearExistingPriceVersions(ServiceDefinition $service): void
    {
        PriceVersion::query()
            ->where('priceable_type', ServiceDefinition::class)
            ->where('priceable_id', $service->id)
            ->delete();
    }

    public function test_recording_a_first_price_version_creates_version_one_as_current(): void
    {
        $service = ServiceDefinition::findByCode(ServiceCode::GRAVE_DIGGING);
        $this->clearExistingPriceVersions($service);

        $priceVersion = (new RecordServiceDefinitionPriceVersion)(
            serviceDefinition: $service,
            amount: '1500000.00',
            actorReference: 3,
            source: 'Rate card operator uji.',
        );

        $this->assertSame(1, $priceVersion->version_number);
        $this->assertSame('1500000.00', $priceVersion->amount);
        $this->assertSame('IDR', $priceVersion->currency);
        $this->assertTrue($priceVersion->isCurrent());
        $this->assertSame($priceVersion->id, $service->currentPriceVersion()?->id);
    }

    public function test_recording_a_second_price_version_supersedes_the_first_and_becomes_current(): void
    {
        $service = ServiceDefinition::findByCode(ServiceCode::GRAVE_DIGGING);
        $this->clearExistingPriceVersions($service);

        $first = (new RecordServiceDefinitionPriceVersion)(
            serviceDefinition: $service,
            amount: '1500000.00',
            actorReference: 3,
        );

        $second = (new RecordServiceDefinitionPriceVersion)(
            serviceDefinition: $service,
            amount: '1750000.00',
            actorReference: 3,
        );

        $this->assertSame(1, $first->version_number);
        $this->assertSame(2, $second->version_number);
        $this->assertTrue($second->isCurrent());

        $this->assertFalse($first->refresh()->isCurrent());
        $this->assertNotNull($first->refresh()->superseded_at);

        $current = $service->currentPriceVersion();
        $this->assertNotNull($current);
        $this->assertSame($second->id, $current->id);
        $this->assertSame('1750000.00', $current->amount);
    }

    public function test_two_different_services_version_their_prices_independently(): void
    {
        $graveDigging = ServiceDefinition::findByCode(ServiceCode::GRAVE_DIGGING);
        $ambulance = ServiceDefinition::findByCode(ServiceCode::AMBULANCE);
        $this->clearExistingPriceVersions($graveDigging);
        $this->clearExistingPriceVersions($ambulance);

        $recordPrice = new RecordServiceDefinitionPriceVersion;
        $recordPrice(serviceDefinition: $graveDigging, amount: '1500000.00', actorReference: 3);
        $ambulancePrice = $recordPrice(serviceDefinition: $ambulance, amount: '800000.00', actorReference: 3);

        $this->assertSame(1, $ambulancePrice->version_number);
        $this->assertSame($ambulance->id, $ambulancePrice->priceable_id);
    }

    /**
     * Replaces this file's previous
     * `test_a_service_with_no_recorded_price_has_no_current_price_version`
     * (which used to assert LIVE_STREAMING had no recorded price at all).
     * That assertion is no longer true for ANY of the 12 known codes once
     * the dev-data migration runs — see this class's own doc block. This
     * test documents the new reality directly instead of asserting a
     * now-false negative.
     */
    public function test_every_known_service_has_a_current_dummy_price_version_out_of_the_box(): void
    {
        foreach (ServiceCode::KNOWN_CODES as $code) {
            $service = ServiceDefinition::findByCode($code);
            $current = $service->currentPriceVersion();

            $this->assertNotNull($current, "[{$code}] should have a current price version from the dev-data migration.");
            $this->assertTrue($current->isCurrent(), "[{$code}] current price version should be unsuperseded.");
            $this->assertGreaterThan(0.0, (float) $current->amount, "[{$code}] price amount should be positive.");
        }
    }

    public function test_a_non_positive_amount_is_rejected(): void
    {
        $service = ServiceDefinition::findByCode(ServiceCode::CATERING);

        $this->expectException(InvalidArgumentException::class);

        (new RecordServiceDefinitionPriceVersion)(serviceDefinition: $service, amount: '0', actorReference: 3);
    }

    public function test_a_non_numeric_amount_is_rejected(): void
    {
        $service = ServiceDefinition::findByCode(ServiceCode::CATERING);

        $this->expectException(InvalidArgumentException::class);

        (new RecordServiceDefinitionPriceVersion)(serviceDefinition: $service, amount: 'not-a-number', actorReference: 3);
    }
}
