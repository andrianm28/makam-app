<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Public\Booking;

use App\Domain\Booking\Actions\SaveBookingDraftStep;
use App\Domain\Booking\Actions\StartBookingDraft;
use App\Domain\Booking\BookingWizardStep;
use App\Domain\CemeteryDirectory\LaunchCityCode;
use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Livewire\Public\Booking\BookingWizard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Regression for the Step 5 (Ringkasan) dead-end: after completing Step 4,
 * the user lands on the summary with no forward control and Step 6 was
 * unreachable via `goToStep()` (canReachStep returned false for CUSTOMER_DATA
 * until it was already saved). The journey must hand off from the summary
 * into Step 6 (Data Pemesan) — a live user reported being stuck on Step 5
 * with no way to reach Steps 6-9 on dev.
 */
final class BookingWizardStepFiveToSixHandoffTest extends TestCase
{
    use RefreshDatabase;

    private function draftAtSummary(): string
    {
        $draft = (new StartBookingDraft)();
        $draft = (new SaveBookingDraftStep)($draft, BookingWizardStep::LOCATION, ['city_code' => LaunchCityCode::JAKARTA], 'idem-a');

        $cemetery = Cemetery::query()
            ->where('city', LaunchCityCode::JAKARTA)
            ->where('publication_status', 'published')
            ->whereDoesntHave('packages')
            ->firstOrFail();

        $draft = (new SaveBookingDraftStep)($draft, BookingWizardStep::CEMETERY, ['cemetery_id' => $cemetery->id], 'idem-b');
        $draft = (new SaveBookingDraftStep)($draft, BookingWizardStep::SERVICE_TYPE, ['service_type' => 'NEW_GRAVE'], 'idem-c');

        return (new SaveBookingDraftStep)($draft, BookingWizardStep::SERVICES, [
            'selected_services' => [
                ['code' => 'DOCUMENT_PROCESSING', 'quantity' => 1],
                ['code' => 'GRAVE_DIGGING', 'quantity' => 1],
            ],
        ], 'idem-d')->id;
    }

    public function test_the_summary_offers_a_forward_path_into_step_6(): void
    {
        $draftId = $this->draftAtSummary();

        Livewire::test(BookingWizard::class, ['draftId' => $draftId])
            ->call('saveStep4', [
                ['code' => 'DOCUMENT_PROCESSING', 'quantity' => 1],
                ['code' => 'GRAVE_DIGGING', 'quantity' => 1],
            ])
            ->assertSet('currentStep', BookingWizardStep::SUMMARY)
            // The summary must offer the user a way forward into Step 6 —
            // a "Lanjut ke Data Pemesan" control — not strand them.
            ->assertSee('Lanjut ke Data Pemesan');
    }

    public function test_step_6_is_reachable_from_the_summary_via_go_to_step(): void
    {
        $draftId = $this->draftAtSummary();

        Livewire::test(BookingWizard::class, ['draftId' => $draftId])
            ->call('saveStep4', [
                ['code' => 'DOCUMENT_PROCESSING', 'quantity' => 1],
                ['code' => 'GRAVE_DIGGING', 'quantity' => 1],
            ])
            ->call('goToStep', BookingWizardStep::CUSTOMER_DATA)
            ->assertSet('currentStep', BookingWizardStep::CUSTOMER_DATA)
            ->assertSee('Langkah 6');
    }

    public function test_step_6_render_includes_the_customer_data_form_fields(): void
    {
        $draftId = $this->draftAtSummary();

        Livewire::test(BookingWizard::class, ['draftId' => $draftId])
            ->call('saveStep4', [
                ['code' => 'DOCUMENT_PROCESSING', 'quantity' => 1],
                ['code' => 'GRAVE_DIGGING', 'quantity' => 1],
            ])
            ->call('goToStep', BookingWizardStep::CUSTOMER_DATA)
            ->assertSee('Nama Lengkap')
            ->assertSee('customer-full-name')
            ->assertSeeHtml('wire:submit="saveStep6"');
    }
}
