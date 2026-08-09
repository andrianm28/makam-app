<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Booking\Actions;

use App\Domain\Booking\Actions\SaveBookingDraftStep;
use App\Domain\Booking\BookingWizardStep;
use App\Domain\Booking\Exceptions\BookingStepValidationException;
use App\Domain\Booking\Models\BookingDraft;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SaveBookingDraftStepServicesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Step 4 is only reachable once step 3 is done —
     * `SaveBookingDraftStep::validateStepSequencing()` rejects a step whose
     * predecessor is not in the draft's own `completed_steps`
     * (`public-booking-wizard` AC13, "user cannot skip required upstream
     * decisions"). These are step-4 VALIDATION tests, so the fixture starts
     * from a draft that legitimately reached step 4 rather than a bare one.
     */
    private function draftReadyForStep4(): BookingDraft
    {
        return BookingDraft::create(['completed_steps' => [1, 2, 3]]);
    }

    public function test_step_4_accepts_both_basic_services_plus_an_addon(): void
    {
        $draft = $this->draftReadyForStep4();

        $saved = (new SaveBookingDraftStep)($draft, BookingWizardStep::SERVICES, [
            'selected_services' => [
                ['code' => 'DOCUMENT_PROCESSING', 'quantity' => 1],
                ['code' => 'GRAVE_DIGGING', 'quantity' => 1],
                ['code' => 'FLOWERS', 'quantity' => 2],
            ],
        ], 'idem-svc-1');

        $this->assertCount(3, $saved->selected_services);
        $this->assertSame(BookingWizardStep::SUMMARY, $saved->current_step);
    }

    public function test_step_4_rejects_a_selection_missing_document_processing(): void
    {
        $draft = $this->draftReadyForStep4();

        try {
            (new SaveBookingDraftStep)($draft, BookingWizardStep::SERVICES, [
                'selected_services' => [
                    ['code' => 'GRAVE_DIGGING', 'quantity' => 1],
                ],
            ], 'idem-svc-2');
            $this->fail('Expected BookingStepValidationException — DOCUMENT_PROCESSING is a mandatory basic service.');
        } catch (BookingStepValidationException $e) {
            $this->assertArrayHasKey('selected_services', $e->getErrors());
        }
    }

    public function test_step_4_rejects_a_selection_missing_grave_digging(): void
    {
        $draft = $this->draftReadyForStep4();

        $this->expectException(BookingStepValidationException::class);

        (new SaveBookingDraftStep)($draft, BookingWizardStep::SERVICES, [
            'selected_services' => [
                ['code' => 'DOCUMENT_PROCESSING', 'quantity' => 1],
            ],
        ], 'idem-svc-3');
    }

    public function test_step_4_rejects_an_unknown_service_code(): void
    {
        $draft = $this->draftReadyForStep4();

        $this->expectException(BookingStepValidationException::class);

        (new SaveBookingDraftStep)($draft, BookingWizardStep::SERVICES, [
            'selected_services' => [
                ['code' => 'DOCUMENT_PROCESSING', 'quantity' => 1],
                ['code' => 'GRAVE_DIGGING', 'quantity' => 1],
                ['code' => 'CREMATION', 'quantity' => 1],
            ],
        ], 'idem-svc-4');
    }

    public function test_step_4_rejects_a_non_positive_quantity(): void
    {
        $draft = $this->draftReadyForStep4();

        $this->expectException(BookingStepValidationException::class);

        (new SaveBookingDraftStep)($draft, BookingWizardStep::SERVICES, [
            'selected_services' => [
                ['code' => 'DOCUMENT_PROCESSING', 'quantity' => 1],
                ['code' => 'GRAVE_DIGGING', 'quantity' => 1],
                ['code' => 'FLOWERS', 'quantity' => 0],
            ],
        ], 'idem-svc-5');
    }

    public function test_step_4_rejects_an_empty_selection(): void
    {
        $draft = $this->draftReadyForStep4();

        $this->expectException(BookingStepValidationException::class);

        (new SaveBookingDraftStep)($draft, BookingWizardStep::SERVICES, ['selected_services' => []], 'idem-svc-6');
    }

    /**
     * One row per service — quantity expresses "more than one", never a
     * repeated code. A duplicate would double-count in
     * `BookingDraftQuery::summary()`'s total and render the same line twice
     * on Step 5.
     */
    public function test_step_4_rejects_the_same_service_code_twice(): void
    {
        $draft = $this->draftReadyForStep4();

        try {
            (new SaveBookingDraftStep)($draft, BookingWizardStep::SERVICES, [
                'selected_services' => [
                    ['code' => 'DOCUMENT_PROCESSING', 'quantity' => 1],
                    ['code' => 'GRAVE_DIGGING', 'quantity' => 1],
                    ['code' => 'FLOWERS', 'quantity' => 1],
                    ['code' => 'FLOWERS', 'quantity' => 2],
                ],
            ], 'idem-svc-7');
            $this->fail('Expected BookingStepValidationException — FLOWERS is selected twice.');
        } catch (BookingStepValidationException $e) {
            $this->assertArrayHasKey('selected_services', $e->getErrors());
        }

        $this->assertSame([], BookingDraft::query()->findOrFail($draft->id)->selected_services);
    }

    public function test_step_4_rejects_a_duplicated_basic_service(): void
    {
        $draft = $this->draftReadyForStep4();

        $this->expectException(BookingStepValidationException::class);

        (new SaveBookingDraftStep)($draft, BookingWizardStep::SERVICES, [
            'selected_services' => [
                ['code' => 'DOCUMENT_PROCESSING', 'quantity' => 1],
                ['code' => 'DOCUMENT_PROCESSING', 'quantity' => 1],
                ['code' => 'GRAVE_DIGGING', 'quantity' => 1],
            ],
        ], 'idem-svc-8');
    }
}
