<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Public\Booking;

use App\Domain\Booking\BookingServiceType;
use App\Domain\Booking\BookingWizardScreen;
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

    private function jakartaCemeteryWithoutPackages(): Cemetery
    {
        return Cemetery::query()
            ->where('city', LaunchCityCode::JAKARTA)
            ->where('publication_status', 'published')
            ->whereDoesntHave('packages')
            ->firstOrFail();
    }

    /**
     * The whole of Screen 1 in one session, through the real controls the
     * Blade view renders: three local selections, then the ONE save.
     */
    public function test_a_visitor_can_complete_the_whole_discovery_screen_in_one_session(): void
    {
        $cemetery = $this->jakartaCemeteryWithoutPackages();

        $component = Livewire::test(BookingWizard::class)
            ->call('selectCity', LaunchCityCode::JAKARTA)
            ->call('selectCemetery', $cemetery->id)
            ->call('selectServiceType', BookingServiceType::NEW_GRAVE)
            ->assertSet('currentStep', BookingWizardStep::DISCOVERY)
            ->call('continueFromDiscovery')
            ->assertSet('currentStep', BookingWizardStep::CUSTOMER_AND_DECEASED_DATA);

        $draftId = $component->get('draftId');
        $this->assertNotNull($draftId);

        $this->assertDatabaseHas('booking_drafts', [
            'id' => $draftId,
            'city_code' => 'JAKARTA',
            'cemetery_id' => $cemetery->id,
            'service_type' => 'NEW_GRAVE',
            'current_step' => BookingWizardStep::CUSTOMER_AND_DECEASED_DATA,
        ]);
    }

    /**
     * The service-type buttons used to render the raw `BookingServiceType`
     * codes to the user. The labels come from `mvp-scope.md` row 3 /
     * `product-brief.md` §3 ("Makam Baru, Makam Tumpang, Urgent, Pre-Need") —
     * copied, not invented.
     */
    public function test_the_service_type_section_shows_product_labels_not_raw_enum_codes(): void
    {
        $component = Livewire::test(BookingWizard::class)
            ->call('selectCity', LaunchCityCode::JAKARTA)
            ->call('selectCemetery', $this->jakartaCemeteryWithoutPackages()->id);

        foreach (BookingServiceType::LABELS as $code => $label) {
            // `<x-mk.button>` renders its slot inside a bare `<span>`, so
            // this asserts the VISIBLE copy specifically, not merely that
            // the string occurs somewhere in the markup.
            $component->assertSeeHtml("<span>{$label}</span>");
            $component->assertDontSeeHtml("<span>{$code}</span>");
            // The code survives only inside the `wire:click` payload — it is
            // still what the (non-persisting) setter receives, and what the
            // one DISCOVERY save later persists.
            $component->assertSeeHtml("selectServiceType('{$code}')");
        }
    }

    public function test_resuming_a_partially_completed_draft_skips_straight_to_its_saved_step(): void
    {
        $cemetery = Cemetery::query()
            ->where('city', LaunchCityCode::BOGOR)
            ->where('publication_status', 'published')
            ->whereDoesntHave('packages')
            ->firstOrFail();

        $draftId = Livewire::test(BookingWizard::class)
            ->call('selectCity', LaunchCityCode::BOGOR)
            ->call('selectCemetery', $cemetery->id)
            ->call('selectServiceType', BookingServiceType::NEW_GRAVE)
            ->call('continueFromDiscovery')
            ->get('draftId');

        Livewire::test(BookingWizard::class, ['draftId' => $draftId])
            ->assertSet('currentStep', BookingWizardStep::CUSTOMER_AND_DECEASED_DATA)
            ->assertSet('city', LaunchCityCode::BOGOR);
    }

    public function test_a_double_submitted_discovery_does_not_create_two_drafts_from_one_click(): void
    {
        // Simulates a double-tap: the SAME component instance saving
        // DISCOVERY twice in quick succession would, in the real Livewire
        // request lifecycle, mean the second call already has $draftId set
        // from the first — this proves the second call updates the existing
        // draft rather than creating a second one, once $draftId is known.
        $cemetery = $this->jakartaCemeteryWithoutPackages();

        $component = Livewire::test(BookingWizard::class)
            ->call('selectCity', LaunchCityCode::JAKARTA)
            ->call('selectCemetery', $cemetery->id)
            ->call('selectServiceType', BookingServiceType::NEW_GRAVE)
            ->call('continueFromDiscovery');

        $this->assertDatabaseCount('booking_drafts', 1);

        $draftId = $component->get('draftId');

        Livewire::test(BookingWizard::class, ['draftId' => $draftId])
            ->call('continueFromDiscovery');

        $this->assertDatabaseCount('booking_drafts', 1);
    }

    /**
     * The stepper always shows the WHOLE journey, including the screens the
     * customer has not reached. Formerly "never removes steps 6 through 9";
     * the same guarantee, against the four-screen rail
     * (`BookingWizardScreen::labels()`) the reduction replaced them with.
     */
    public function test_the_stepper_never_removes_the_screens_after_the_current_one(): void
    {
        $component = Livewire::test(BookingWizard::class);

        foreach (BookingWizardScreen::LABELS as $label) {
            $component->assertSee($label);
        }
    }
}
