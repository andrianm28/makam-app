<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\ServiceCatalog;

use App\Domain\ServiceCatalog\Models\ServiceDefinition;
use App\Domain\ServiceCatalog\ServiceCatalogQuery;
use App\Domain\ServiceCatalog\ServiceCategory;
use App\Domain\ServiceCatalog\ServiceCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `2026_07_26_180700_seed_service_definitions_from_catalog.php` seeds
 * exactly the 12 codes `docs/product/service-catalog.md` names — this test
 * covers the column-shape/default assertions
 * `ServiceCodeDriftTest` does not (that test's own focus is catalogue
 * fidelity; this one is seed defaults, active-scope behaviour, and
 * `ServiceCatalogQuery`'s read helpers).
 */
final class ServiceDefinitionSeedTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_seeded_row_is_active_with_no_description_and_no_schedule_or_confirmation_flags(): void
    {
        foreach (ServiceDefinition::all() as $definition) {
            $this->assertTrue($definition->is_active, "[{$definition->code}] should seed active.");
            $this->assertNull($definition->description, "[{$definition->code}] should seed with no description.");
            $this->assertFalse($definition->requires_schedule, "[{$definition->code}] should not seed requires_schedule.");
            $this->assertFalse(
                $definition->requires_manual_confirmation,
                "[{$definition->code}] should not seed requires_manual_confirmation."
            );
        }
    }

    public function test_find_by_code_resolves_every_known_code_and_nothing_else(): void
    {
        foreach (ServiceCode::KNOWN_CODES as $code) {
            $this->assertNotNull(ServiceDefinition::findByCode($code), "Expected a seeded service for [{$code}].");
        }

        $this->assertNull(ServiceDefinition::findByCode('NOT_A_REAL_CODE'));
    }

    public function test_service_catalog_query_basic_services_returns_exactly_the_two_basic_codes(): void
    {
        $codes = ServiceCatalogQuery::basicServices()->pluck('code')->all();

        $this->assertSame(ServiceCode::BASIC_CODES, $codes);
    }

    public function test_service_catalog_query_additional_services_returns_exactly_the_ten_additional_codes(): void
    {
        $codes = ServiceCatalogQuery::additionalServices()->pluck('code')->all();

        $this->assertSame(ServiceCode::ADDITIONAL_CODES, $codes);
    }

    public function test_service_catalog_query_all_active_returns_all_twelve_basic_first(): void
    {
        $codes = ServiceCatalogQuery::allActive()->pluck('code')->all();

        $this->assertSame(ServiceCode::KNOWN_CODES, $codes);
    }

    public function test_deactivating_a_service_removes_it_from_active_query_helpers_but_not_find_by_code(): void
    {
        $definition = ServiceDefinition::findByCode(ServiceCode::LIVE_STREAMING);
        $this->assertNotNull($definition);

        $definition->forceFill(['is_active' => false])->save();

        $this->assertFalse(ServiceCatalogQuery::allActive()->pluck('code')->contains(ServiceCode::LIVE_STREAMING));
        $this->assertFalse(ServiceCatalogQuery::additionalServices()->pluck('code')->contains(ServiceCode::LIVE_STREAMING));

        // findByCode is not an "active only" helper — an admin surface still
        // needs to find and reactivate an inactive row.
        $this->assertNotNull(ServiceDefinition::findByCode(ServiceCode::LIVE_STREAMING));
    }

    public function test_saving_an_unknown_code_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ServiceDefinition::create([
            'code' => 'NOT_A_REAL_CODE',
            'name' => 'Invalid',
            'category' => ServiceCategory::BASIC,
        ]);
    }

    public function test_saving_an_unknown_category_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ServiceDefinition::create([
            'code' => ServiceCode::CATERING,
            'name' => 'Konsumsi',
            'category' => 'not-a-real-category',
        ]);
    }

    public function test_saving_an_unknown_fulfillment_owner_is_rejected(): void
    {
        $definition = ServiceDefinition::findByCode(ServiceCode::CATERING);
        $this->assertNotNull($definition);

        $this->expectException(\InvalidArgumentException::class);

        $definition->forceFill(['fulfillment_owner' => 'not-a-real-owner'])->save();
    }
}
