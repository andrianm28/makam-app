<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Quotation;

use App\Domain\Booking\Actions\SaveBookingDraftStep;
use App\Domain\Booking\Actions\StartBookingDraft;
use App\Domain\Booking\BookingServiceType;
use App\Domain\Booking\BookingWizardStep;
use App\Domain\Booking\Models\BookingDraft;
use App\Domain\CemeteryDirectory\CemeteryPublicQuery;
use App\Domain\Quotation\Actions\ComposeQuoteLinesFromBookingDraft;
use App\Domain\Quotation\Exceptions\UnpricedBookingServiceException;
use App\Domain\ServiceCatalog\Models\PriceVersion;
use App\Domain\ServiceCatalog\Models\ServiceDefinition;
use App\Domain\ServiceCatalog\ServiceCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\Support\CemeteryFixture;
use Tests\TestCase;

/**
 * Task 2 of `docs/superpowers/plans/2026-08-14-p0-booking-submission-chain.md`
 * — the quote-line mapper that turns a booking draft's `selected_services`
 * into the SERVICE-VERSION lines shape `Actions\IssueQuote` consumes (the
 * 14 Aug ruling: service lines carrying `service_definition_id` +
 * `price_version_id`, never a synthesized package version).
 *
 * Drafts are built through the wizard's own write path
 * (`StartBookingDraft` + `SaveBookingDraftStep`, steps 1-4 — no
 * `BookingDraft` factory exists, so this suite follows the fixture style of
 * `tests/Feature/Domain/Booking/Actions/SaveBookingDraftStepSteps678Test.php`),
 * so the mapper is exercised against exactly the persisted shape the wizard
 * produces. The seeded `DOCUMENT_PROCESSING` / `GRAVE_DIGGING` definitions
 * both carry a v1 dummy price version
 * (`2026_07_26_220000_seed_service_definition_dummy_operational_data.php`),
 * with deterministic amounts: 350000.00 and 550000.00 respectively
 * (`App\Support\ExampleData\ServiceOperationalExampleData::dummyPrices()`),
 * and catalogue fulfilment owners (platform / cemetery_operator).
 */
final class ComposeQuoteLinesFromBookingDraftTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Drives a real draft through steps 1-4 of the wizard's own save path.
     *
     * @param  list<array{code: string, quantity: int}>  $services
     */
    private function draftWithSelectedServices(array $services): BookingDraft
    {
        $draft = (new StartBookingDraft)();
        $cemetery = CemeteryFixture::cemetery('package', 0);
        $package = CemeteryPublicQuery::activePackages($cemetery)->firstOrFail();

        $draft = (new SaveBookingDraftStep)($draft, BookingWizardStep::LOCATION, ['city_code' => $cemetery->city], 'idem-s1');
        $draft = (new SaveBookingDraftStep)($draft, BookingWizardStep::CEMETERY, ['cemetery_id' => $cemetery->id, 'cemetery_package_id' => $package->id], 'idem-s2');
        $draft = (new SaveBookingDraftStep)($draft, BookingWizardStep::SERVICE_TYPE, ['service_type' => BookingServiceType::NEW_GRAVE], 'idem-s3');

        return (new SaveBookingDraftStep)($draft, BookingWizardStep::SERVICES, ['selected_services' => $services], 'idem-s4');
    }

    public function test_it_maps_selected_services_to_service_version_quote_lines_with_current_prices(): void
    {
        $draft = $this->draftWithSelectedServices([
            ['code' => ServiceCode::DOCUMENT_PROCESSING, 'quantity' => 1],
            ['code' => ServiceCode::GRAVE_DIGGING, 'quantity' => 2],
        ]);

        $lines = (new ComposeQuoteLinesFromBookingDraft)($draft);

        $this->assertCount(2, $lines);
        foreach ($lines as $line) {
            $this->assertArrayHasKey('service_definition_id', $line);
            $this->assertArrayHasKey('price_version_id', $line);
            $this->assertArrayHasKey('price_version_number', $line);
            $this->assertArrayHasKey('quantity', $line);
            $this->assertMatchesRegularExpression('/^\d+\.\d{2}$/', $line['unit_amount']);
            $this->assertMatchesRegularExpression('/^[A-Z]{3}$/', $line['currency']);
            $this->assertIsString($line['fulfillment_owner']);
            $this->assertArrayNotHasKey('code', $line);
            $this->assertArrayNotHasKey('service_package_version_id', $line);
            $this->assertArrayNotHasKey('line_total_minor', $line);
        }

        // Exact values are pinned to the deterministic dummy-price seed and
        // the catalogue fulfilment-owner rules: DOCUMENT_PROCESSING is
        // platform-fulfilled at 350000.00; GRAVE_DIGGING is
        // cemetery-operator-fulfilled at 550000.00.
        $documents = ServiceDefinition::findByCode(ServiceCode::DOCUMENT_PROCESSING);
        $documentsPrice = $documents->currentPriceVersion();
        $digging = ServiceDefinition::findByCode(ServiceCode::GRAVE_DIGGING);
        $diggingPrice = $digging->currentPriceVersion();

        $this->assertSame([
            [
                'service_definition_id' => (int) $documents->getKey(),
                'price_version_id' => (int) $documentsPrice->getKey(),
                'price_version_number' => 1,
                'quantity' => 1,
                'unit_amount' => '350000.00',
                'currency' => 'IDR',
                'fulfillment_owner' => 'platform',
            ],
            [
                'service_definition_id' => (int) $digging->getKey(),
                'price_version_id' => (int) $diggingPrice->getKey(),
                'price_version_number' => 1,
                'quantity' => 2,
                'unit_amount' => '550000.00',
                'currency' => 'IDR',
                'fulfillment_owner' => 'cemetery_operator',
            ],
        ], $lines);
    }

    public function test_it_throws_when_a_selected_service_has_no_current_price(): void
    {
        $definition = ServiceDefinition::findByCode(ServiceCode::GRAVE_DIGGING);
        $this->assertNotNull($definition);

        // Supersede every price row of the one definition (builder-level,
        // documented in `PriceVersion`'s doc block to bypass the
        // append-only model guard) so `currentPriceVersion()` is null.
        PriceVersion::query()
            ->where('priceable_type', ServiceDefinition::class)
            ->where('priceable_id', $definition->id)
            ->update(['superseded_at' => now()->toDateTimeString()]);

        $draft = $this->draftWithSelectedServices([
            ['code' => ServiceCode::DOCUMENT_PROCESSING, 'quantity' => 1],
            ['code' => ServiceCode::GRAVE_DIGGING, 'quantity' => 1],
        ]);

        $this->expectException(UnpricedBookingServiceException::class);
        $this->expectExceptionMessage(ServiceCode::GRAVE_DIGGING);

        (new ComposeQuoteLinesFromBookingDraft)($draft);
    }

    public function test_it_throws_when_a_selected_service_code_is_unknown(): void
    {
        // An unknown code can never reach a draft through the wizard (step 4
        // rejects it), so this fixture is written directly — the
        // hand-edited-JSON-column case the mapper must fail loudly on.
        $draft = BookingDraft::create([
            'selected_services' => [
                ['code' => ServiceCode::DOCUMENT_PROCESSING, 'quantity' => 1],
                ['code' => 'NOT_A_REAL_SERVICE', 'quantity' => 1],
            ],
        ]);

        $this->expectException(UnpricedBookingServiceException::class);
        $this->expectExceptionMessage('NOT_A_REAL_SERVICE');

        (new ComposeQuoteLinesFromBookingDraft)($draft);
    }

    public function test_it_rejects_a_malformed_selected_services_entry(): void
    {
        // Same hand-edited-column rationale as the unknown-code test: a
        // non-array entry must surface as a clear error, never a raw PHP
        // warning that could underquote an order by skipping the line.
        $draft = BookingDraft::create([
            'selected_services' => [
                ['code' => ServiceCode::DOCUMENT_PROCESSING, 'quantity' => 1],
                'junk-entry',
            ],
        ]);

        $this->expectException(InvalidArgumentException::class);

        (new ComposeQuoteLinesFromBookingDraft)($draft);
    }
}
