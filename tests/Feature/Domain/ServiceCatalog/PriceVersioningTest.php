<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\ServiceCatalog;

use App\Domain\ServiceCatalog\Actions\RecordServiceDefinitionPriceVersion;
use App\Domain\ServiceCatalog\Models\ServiceDefinition;
use App\Domain\ServiceCatalog\ServiceCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Proves the `price_versions` versioning mechanism `service-catalog.md`
 * "Catalog rules" requires ("Price is versioned and snapshot into
 * quote/order") against a real `service_definitions` row. Uses synthetic
 * test-only Rupiah amounts, never real catalogue pricing — see
 * `2026_07_26_180400_create_price_versions_table.php`'s own doc block for
 * why no price is seeded.
 */
final class PriceVersioningTest extends TestCase
{
    use RefreshDatabase;

    public function test_recording_a_first_price_version_creates_version_one_as_current(): void
    {
        $service = ServiceDefinition::findByCode(ServiceCode::GRAVE_DIGGING);

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
        $this->assertSame($service->id, $service->currentPriceVersion()?->id);
    }

    public function test_recording_a_second_price_version_supersedes_the_first_and_becomes_current(): void
    {
        $service = ServiceDefinition::findByCode(ServiceCode::GRAVE_DIGGING);

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

        $recordPrice = new RecordServiceDefinitionPriceVersion;
        $recordPrice(serviceDefinition: $graveDigging, amount: '1500000.00', actorReference: 3);
        $ambulancePrice = $recordPrice(serviceDefinition: $ambulance, amount: '800000.00', actorReference: 3);

        $this->assertSame(1, $ambulancePrice->version_number);
        $this->assertSame($ambulance->id, $ambulancePrice->priceable_id);
    }

    public function test_a_service_with_no_recorded_price_has_no_current_price_version(): void
    {
        $service = ServiceDefinition::findByCode(ServiceCode::LIVE_STREAMING);

        $this->assertNull($service->currentPriceVersion());
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
