<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Public\Booking;

use App\Domain\Booking\BookingWizardStep;
use App\Domain\CemeteryDirectory\LaunchCityCode;
use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Livewire\Public\Booking\BookingWizard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

final class BookingWizardEndToEndTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_visitor_can_complete_steps_1_through_5_in_one_session(): void
    {
        $cemetery = Cemetery::query()
            ->where('city', LaunchCityCode::JAKARTA)
            ->where('publication_status', 'published')
            ->whereDoesntHave('packages')
            ->firstOrFail();

        $component = Livewire::test(BookingWizard::class)
            ->call('saveStep1', LaunchCityCode::JAKARTA)
            ->assertSet('currentStep', BookingWizardStep::CEMETERY);

        $draftId = $component->get('draftId');
        $this->assertNotNull($draftId);

        $component = Livewire::test(BookingWizard::class, ['draftId' => $draftId])
            ->call('saveStep2', $cemetery->id)
            ->assertSet('currentStep', BookingWizardStep::SERVICE_TYPE)
            ->call('saveStep3', 'NEW_GRAVE')
            ->assertSet('currentStep', BookingWizardStep::SERVICES)
            ->call('saveStep4', [
                ['code' => 'DOCUMENT_PROCESSING', 'quantity' => 1],
                ['code' => 'GRAVE_DIGGING', 'quantity' => 1],
            ])
            ->assertSet('currentStep', BookingWizardStep::SUMMARY);

        $this->assertDatabaseHas('booking_drafts', [
            'id' => $draftId,
            'city_code' => 'JAKARTA',
            'cemetery_id' => $cemetery->id,
            'service_type' => 'NEW_GRAVE',
            'current_step' => BookingWizardStep::SUMMARY,
        ]);
    }

    public function test_resuming_a_partially_completed_draft_skips_straight_to_its_saved_step(): void
    {
        $component = Livewire::test(BookingWizard::class)->call('saveStep1', LaunchCityCode::BOGOR);
        $draftId = $component->get('draftId');

        Livewire::test(BookingWizard::class, ['draftId' => $draftId])
            ->assertSet('currentStep', BookingWizardStep::CEMETERY)
            ->assertSet('city', LaunchCityCode::BOGOR);
    }

    public function test_a_double_submitted_step_1_does_not_create_two_drafts_from_one_click(): void
    {
        // Simulates a double-tap: the SAME component instance calling
        // saveStep1 twice in quick succession would, in the real Livewire
        // request lifecycle, mean the second call already has $draftId set
        // from the first — this proves the second call updates the
        // existing draft rather than creating a second one, once $draftId
        // is known.
        $component = Livewire::test(BookingWizard::class)
            ->call('saveStep1', LaunchCityCode::JAKARTA);

        $this->assertDatabaseCount('booking_drafts', 1);

        $draftId = $component->get('draftId');

        Livewire::test(BookingWizard::class, ['draftId' => $draftId])
            ->call('saveStep1', LaunchCityCode::JAKARTA);

        $this->assertDatabaseCount('booking_drafts', 1);
    }

    public function test_the_stepper_never_removes_steps_6_through_9_even_though_they_are_unbuilt(): void
    {
        $component = Livewire::test(BookingWizard::class);

        foreach (BookingWizardStep::LABELS as $step => $label) {
            $component->assertSee($label);
        }
    }
}
