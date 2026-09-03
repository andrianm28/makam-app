<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Booking\Actions;

use App\Domain\Booking\Actions\SaveBookingDraftStep;
use App\Domain\Booking\BookingServiceType;
use App\Domain\Booking\BookingWizardStep;
use App\Domain\Booking\Exceptions\BookingStepValidationException;
use App\Domain\Booking\Models\BookingDraft;
use App\Domain\CemeteryDirectory\CemeteryPublicationStatus;
use App\Domain\CemeteryDirectory\LaunchCityCode;
use App\Domain\CemeteryDirectory\Models\Cemetery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Service-selection validation, now exercised through the merged `DISCOVERY`
 * step (formerly the standalone `SERVICES` step, old step 4) —
 * `SaveBookingDraftStep::validateServices()` itself is unchanged; only the
 * step it is reached through changed.
 */
final class SaveBookingDraftStepServicesTest extends TestCase
{
    use RefreshDatabase;

    private function jakartaCemeteryWithoutPackages(): Cemetery
    {
        return Cemetery::query()
            ->where('city', LaunchCityCode::JAKARTA)
            ->where('publication_status', CemeteryPublicationStatus::PUBLISHED)
            ->whereDoesntHave('packages')
            ->firstOrFail();
    }

    /**
     * @param  array<int, array{code: string, quantity: int}>  $selectedServices
     * @return array<string, mixed>
     */
    private function discoveryPayloadWithServices(array $selectedServices): array
    {
        return [
            'city_code' => LaunchCityCode::JAKARTA,
            'cemetery_id' => $this->jakartaCemeteryWithoutPackages()->id,
            'cemetery_package_id' => null,
            'service_type' => BookingServiceType::NEW_GRAVE,
            'selected_services' => $selectedServices,
        ];
    }

    public function test_discovery_step_accepts_both_basic_services_plus_an_addon(): void
    {
        $draft = BookingDraft::create([]);

        $saved = (new SaveBookingDraftStep)($draft, BookingWizardStep::DISCOVERY, $this->discoveryPayloadWithServices([
            ['code' => 'DOCUMENT_PROCESSING', 'quantity' => 1],
            ['code' => 'GRAVE_DIGGING', 'quantity' => 1],
            ['code' => 'FLOWERS', 'quantity' => 2],
        ]), 'idem-svc-1');

        $this->assertCount(3, $saved->selected_services);
        $this->assertSame(BookingWizardStep::CUSTOMER_AND_DECEASED_DATA, $saved->current_step);
    }

    public function test_discovery_step_rejects_a_selection_missing_document_processing(): void
    {
        $draft = BookingDraft::create([]);

        try {
            (new SaveBookingDraftStep)($draft, BookingWizardStep::DISCOVERY, $this->discoveryPayloadWithServices([
                ['code' => 'GRAVE_DIGGING', 'quantity' => 1],
            ]), 'idem-svc-2');
            $this->fail('Expected BookingStepValidationException — DOCUMENT_PROCESSING is a mandatory basic service.');
        } catch (BookingStepValidationException $e) {
            $this->assertArrayHasKey('selected_services', $e->getErrors());
        }
    }

    public function test_discovery_step_rejects_a_selection_missing_grave_digging(): void
    {
        $draft = BookingDraft::create([]);

        $this->expectException(BookingStepValidationException::class);

        (new SaveBookingDraftStep)($draft, BookingWizardStep::DISCOVERY, $this->discoveryPayloadWithServices([
            ['code' => 'DOCUMENT_PROCESSING', 'quantity' => 1],
        ]), 'idem-svc-3');
    }

    public function test_discovery_step_rejects_an_unknown_service_code(): void
    {
        $draft = BookingDraft::create([]);

        $this->expectException(BookingStepValidationException::class);

        (new SaveBookingDraftStep)($draft, BookingWizardStep::DISCOVERY, $this->discoveryPayloadWithServices([
            ['code' => 'DOCUMENT_PROCESSING', 'quantity' => 1],
            ['code' => 'GRAVE_DIGGING', 'quantity' => 1],
            ['code' => 'CREMATION', 'quantity' => 1],
        ]), 'idem-svc-4');
    }

    public function test_discovery_step_rejects_a_non_positive_quantity(): void
    {
        $draft = BookingDraft::create([]);

        $this->expectException(BookingStepValidationException::class);

        (new SaveBookingDraftStep)($draft, BookingWizardStep::DISCOVERY, $this->discoveryPayloadWithServices([
            ['code' => 'DOCUMENT_PROCESSING', 'quantity' => 1],
            ['code' => 'GRAVE_DIGGING', 'quantity' => 1],
            ['code' => 'FLOWERS', 'quantity' => 0],
        ]), 'idem-svc-5');
    }

    public function test_discovery_step_rejects_an_empty_selection(): void
    {
        $draft = BookingDraft::create([]);

        $this->expectException(BookingStepValidationException::class);

        (new SaveBookingDraftStep)($draft, BookingWizardStep::DISCOVERY, $this->discoveryPayloadWithServices([]), 'idem-svc-6');
    }

    /**
     * One row per service — quantity expresses "more than one", never a
     * repeated code. A duplicate would double-count in
     * `BookingDraftQuery::summary()`'s total and render the same line twice
     * on the summary screen.
     */
    public function test_discovery_step_rejects_the_same_service_code_twice(): void
    {
        $draft = BookingDraft::create([]);

        try {
            (new SaveBookingDraftStep)($draft, BookingWizardStep::DISCOVERY, $this->discoveryPayloadWithServices([
                ['code' => 'DOCUMENT_PROCESSING', 'quantity' => 1],
                ['code' => 'GRAVE_DIGGING', 'quantity' => 1],
                ['code' => 'FLOWERS', 'quantity' => 1],
                ['code' => 'FLOWERS', 'quantity' => 2],
            ]), 'idem-svc-7');
            $this->fail('Expected BookingStepValidationException — FLOWERS is selected twice.');
        } catch (BookingStepValidationException $e) {
            $this->assertArrayHasKey('selected_services', $e->getErrors());
        }

        $this->assertSame([], BookingDraft::query()->findOrFail($draft->id)->selected_services);
    }

    public function test_discovery_step_rejects_a_duplicated_basic_service(): void
    {
        $draft = BookingDraft::create([]);

        $this->expectException(BookingStepValidationException::class);

        (new SaveBookingDraftStep)($draft, BookingWizardStep::DISCOVERY, $this->discoveryPayloadWithServices([
            ['code' => 'DOCUMENT_PROCESSING', 'quantity' => 1],
            ['code' => 'DOCUMENT_PROCESSING', 'quantity' => 1],
            ['code' => 'GRAVE_DIGGING', 'quantity' => 1],
        ]), 'idem-svc-8');
    }
}
