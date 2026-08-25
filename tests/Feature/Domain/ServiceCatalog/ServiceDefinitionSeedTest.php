<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\ServiceCatalog;

use App\Domain\ServiceCatalog\FulfillmentOwner;
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
 *
 * `is_active`/`description` are still asserted against the ORIGINAL seed
 * migration's defaults below — those two columns are untouched by the
 * later dummy-data migration. `fulfillment_owner` /`requires_schedule` /
 * `requires_manual_confirmation`, by contrast, are no longer blanket
 * null/false/false: `2026_07_26_220000_seed_service_definition_dummy_operational_data.php`
 * fills them in with explicitly-authorized DUMMY/placeholder values for
 * public display on the dev environment (see that migration's own doc
 * block for the full per-code reasoning and the explicit "not real
 * business data" disclaimer). The tests below were updated accordingly —
 * this file previously asserted these three columns were still at their
 * blanket null/false/false seed defaults, which stopped being true once
 * that migration landed.
 */
final class ServiceDefinitionSeedTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Mirrors `2026_07_26_210000_...`'s own `OPERATIONAL_DEFAULTS` map —
     * deliberately duplicated here (not read from the migration class) so
     * this test acts as a drift detector: if that migration's values ever
     * change without a matching, deliberate test update, this test fails.
     * Same "hardcode it independently, on purpose" convention
     * `ServiceCodeDriftTest::CATALOGUE` already established for the
     * catalogue's own code/label/category values.
     *
     * `code => [fulfillment_owner, requires_schedule, requires_manual_confirmation]`.
     *
     * @var array<string, array{0: string, 1: bool, 2: bool}>
     */
    private const array EXPECTED_DUMMY_OPERATIONAL_DEFAULTS = [
        ServiceCode::DOCUMENT_PROCESSING => [FulfillmentOwner::PLATFORM, false, true],
        ServiceCode::GRAVE_DIGGING => [FulfillmentOwner::CEMETERY_OPERATOR, false, true],

        ServiceCode::AMBULANCE => [FulfillmentOwner::VENDOR, true, false],
        ServiceCode::FUNERAL_HOME => [FulfillmentOwner::VENDOR, false, false],
        ServiceCode::HEARSE => [FulfillmentOwner::VENDOR, true, false],
        ServiceCode::TENT_AND_CHAIRS => [FulfillmentOwner::VENDOR, true, false],
        ServiceCode::SOUND_SYSTEM => [FulfillmentOwner::VENDOR, true, false],
        ServiceCode::FLOWERS => [FulfillmentOwner::VENDOR, false, false],
        ServiceCode::GRAVESTONE => [FulfillmentOwner::VENDOR, false, true],
        ServiceCode::DOCUMENTATION => [FulfillmentOwner::VENDOR, false, false],
        ServiceCode::CATERING => [FulfillmentOwner::VENDOR, true, false],
        ServiceCode::LIVE_STREAMING => [FulfillmentOwner::VENDOR, true, false],
    ];

    public function test_every_seeded_row_is_active_with_no_description(): void
    {
        $definitions = ServiceDefinition::all();

        // M11: without this, the loop below passes vacuously on an empty
        // table — a seed migration that stopped inserting anything would not
        // be noticed here.
        $this->assertNotEmpty($definitions);

        foreach ($definitions as $definition) {
            $this->assertTrue($definition->is_active, "[{$definition->code}] should seed active.");
            $this->assertNull($definition->description, "[{$definition->code}] should seed with no description.");
        }
    }

    public function test_every_seeded_row_has_the_dummy_operational_defaults_from_the_dev_data_migration(): void
    {
        foreach (self::EXPECTED_DUMMY_OPERATIONAL_DEFAULTS as $code => [$owner, $requiresSchedule, $requiresManualConfirmation]) {
            $definition = ServiceDefinition::findByCode($code);
            $this->assertNotNull($definition, "Expected a seeded service for [{$code}].");

            $this->assertSame($owner, $definition->fulfillment_owner, "[{$code}] fulfillment_owner mismatch.");
            $this->assertTrue(FulfillmentOwner::isKnown($definition->fulfillment_owner), "[{$code}] fulfillment_owner should be a known value.");
            $this->assertSame($requiresSchedule, $definition->requires_schedule, "[{$code}] requires_schedule mismatch.");
            $this->assertSame(
                $requiresManualConfirmation,
                $definition->requires_manual_confirmation,
                "[{$code}] requires_manual_confirmation mismatch."
            );
        }
    }

    public function test_every_seeded_service_has_a_recorded_current_price_version_with_a_positive_amount(): void
    {
        foreach (ServiceCode::KNOWN_CODES as $code) {
            $definition = ServiceDefinition::findByCode($code);
            $this->assertNotNull($definition, "Expected a seeded service for [{$code}].");

            $current = $definition->currentPriceVersion();
            $this->assertNotNull($current, "[{$code}] should have a current price version recorded by the dev-data migration.");
            $this->assertSame('IDR', $current->currency, "[{$code}] price currency mismatch.");
            $this->assertGreaterThan(0.0, (float) $current->amount, "[{$code}] price amount should be positive.");
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
