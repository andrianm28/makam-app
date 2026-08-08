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

    public function test_step_4_accepts_both_basic_services_plus_an_addon(): void
    {
        $draft = BookingDraft::create([]);

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
        $draft = BookingDraft::create([]);

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
        $draft = BookingDraft::create([]);

        $this->expectException(BookingStepValidationException::class);

        (new SaveBookingDraftStep)($draft, BookingWizardStep::SERVICES, [
            'selected_services' => [
                ['code' => 'DOCUMENT_PROCESSING', 'quantity' => 1],
            ],
        ], 'idem-svc-3');
    }

    public function test_step_4_rejects_an_unknown_service_code(): void
    {
        $draft = BookingDraft::create([]);

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
        $draft = BookingDraft::create([]);

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
        $draft = BookingDraft::create([]);

        $this->expectException(BookingStepValidationException::class);

        (new SaveBookingDraftStep)($draft, BookingWizardStep::SERVICES, ['selected_services' => []], 'idem-svc-6');
    }
}
