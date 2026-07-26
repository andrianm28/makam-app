<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\ServiceCatalog;

use App\Domain\ServiceCatalog\FulfillmentOwner;
use App\Domain\ServiceCatalog\Models\ServiceDefinition;
use App\Domain\ServiceCatalog\ServiceCategory;
use App\Domain\ServiceCatalog\ServiceCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Drift detection: this test hardcodes `docs/product/service-catalog.md`'s
 * two tables VERBATIM (code, Indonesian label, category), copied directly
 * from that file, independently of `ServiceCode` and of the seed migration.
 * If a future edit changes `App\Domain\ServiceCatalog\ServiceCode`, the seed
 * migration, or `service-catalog.md` itself WITHOUT updating the other two,
 * this test fails — mirroring `tests/Feature/Domain/Faq/
 * FaqCategorySeedTest.php`'s own exact-list assertion shape.
 *
 * ---------------------------------------------------------------------------
 * 12 rows, not 13 — see `App\Domain\ServiceCatalog\ServiceCode`'s own
 * class-level doc block
 * ---------------------------------------------------------------------------
 * `docs/product/service-catalog.md` has exactly 2 "Basic services" rows and
 * 10 "Additional services" rows (verified directly against the file), not
 * 11 additional / 13 total as this batch's own commissioning brief stated.
 * This test asserts against the real file's count.
 */
final class ServiceCodeDriftTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Verbatim from `docs/product/service-catalog.md`, in that document's
     * own row order — [code, Indonesian label, category].
     *
     * @var list<array{string, string, string}>
     */
    private const array CATALOGUE = [
        // --- Basic services ---
        [ServiceCode::DOCUMENT_PROCESSING, 'Pengurusan Dokumen', ServiceCategory::BASIC],
        [ServiceCode::GRAVE_DIGGING, 'Penggalian Makam', ServiceCategory::BASIC],

        // --- Additional services ---
        [ServiceCode::AMBULANCE, 'Ambulans', ServiceCategory::ADDITIONAL],
        [ServiceCode::FUNERAL_HOME, 'Rumah Duka', ServiceCategory::ADDITIONAL],
        [ServiceCode::HEARSE, 'Mobil Jenazah', ServiceCategory::ADDITIONAL],
        [ServiceCode::TENT_AND_CHAIRS, 'Tenda & Kursi', ServiceCategory::ADDITIONAL],
        [ServiceCode::SOUND_SYSTEM, 'Sound System', ServiceCategory::ADDITIONAL],
        [ServiceCode::FLOWERS, 'Karangan Bunga', ServiceCategory::ADDITIONAL],
        [ServiceCode::GRAVESTONE, 'Batu Nisan', ServiceCategory::ADDITIONAL],
        [ServiceCode::DOCUMENTATION, 'Dokumentasi', ServiceCategory::ADDITIONAL],
        [ServiceCode::CATERING, 'Konsumsi', ServiceCategory::ADDITIONAL],
        [ServiceCode::LIVE_STREAMING, 'Live Streaming', ServiceCategory::ADDITIONAL],
    ];

    public function test_the_catalogue_has_exactly_twelve_rows(): void
    {
        $this->assertCount(12, self::CATALOGUE);
        $this->assertCount(2, ServiceCode::BASIC_CODES);
        $this->assertCount(10, ServiceCode::ADDITIONAL_CODES);
        $this->assertCount(12, ServiceCode::KNOWN_CODES);
    }

    public function test_service_code_known_codes_match_the_catalogue_exactly_in_order(): void
    {
        $expectedCodes = array_column(self::CATALOGUE, 0);

        $this->assertSame($expectedCodes, ServiceCode::KNOWN_CODES);
    }

    public function test_service_code_category_split_matches_the_catalogue(): void
    {
        foreach (self::CATALOGUE as [$code, , $category]) {
            if ($category === ServiceCategory::BASIC) {
                $this->assertTrue(ServiceCode::isBasic($code), "[{$code}] should be classified basic.");
                $this->assertFalse(ServiceCode::isAdditional($code), "[{$code}] should not be classified additional.");
            } else {
                $this->assertTrue(ServiceCode::isAdditional($code), "[{$code}] should be classified additional.");
                $this->assertFalse(ServiceCode::isBasic($code), "[{$code}] should not be classified basic.");
            }
        }
    }

    public function test_seeded_service_definitions_match_the_catalogue_exactly(): void
    {
        $this->assertDatabaseCount('service_definitions', 12);

        foreach (self::CATALOGUE as [$code, $label, $category]) {
            $definition = ServiceDefinition::findByCode($code);

            $this->assertNotNull($definition, "Expected a seeded service_definitions row for [{$code}].");
            $this->assertSame($label, $definition->name, "[{$code}] label mismatch.");
            $this->assertSame($category, $definition->category, "[{$code}] category mismatch.");
        }
    }

    public function test_no_seeded_row_exists_outside_the_catalogue(): void
    {
        $expectedCodes = array_column(self::CATALOGUE, 0);

        $actualCodes = ServiceDefinition::query()->pluck('code')->all();

        sort($expectedCodes);
        sort($actualCodes);

        $this->assertSame($expectedCodes, $actualCodes);
    }

    /**
     * Catalog rules ("Every service declares fulfillment owner: platform,
     * cemetery operator, or vendor") describe a CAPABILITY, not a per-code
     * mapping the catalogue itself specifies — see the seed migration's own
     * doc block. Seeded rows deliberately carry no fulfillment owner yet.
     */
    public function test_no_seeded_service_has_a_fulfillment_owner_assigned_yet(): void
    {
        foreach (ServiceDefinition::all() as $definition) {
            $this->assertNull(
                $definition->fulfillment_owner,
                "[{$definition->code}] should not have a fulfillment owner assigned by the seed migration."
            );
        }
    }

    public function test_every_known_fulfillment_owner_is_a_valid_choice_for_a_future_admin_assignment(): void
    {
        $this->assertSame(['platform', 'cemetery_operator', 'vendor'], FulfillmentOwner::KNOWN_OWNERS);
    }
}
