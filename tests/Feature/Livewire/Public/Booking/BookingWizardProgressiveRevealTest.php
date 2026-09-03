<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Public\Booking;

use App\Domain\Booking\BookingServiceType;
use App\Domain\Booking\BookingWizardStep;
use App\Domain\CemeteryDirectory\LaunchCityCode;
use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Livewire\Public\Booking\BookingWizard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Screen 1 ("Cari & Pilih") reveals its four sections — Lokasi, TPU/TPS,
 * Jenis Layanan, Pilih Layanan — one at a time as each choice is made, and
 * keeps every revealed section on the page.
 *
 * The reveal is driven by the component's own LOCAL selection properties
 * (`selectCity()`/`selectCemetery()`/`selectServiceType()`), not by
 * `$currentStep`/`$completedSteps`: all four sections are one merged
 * DISCOVERY step now, so `$currentStep` does not move at all until the single
 * save at the bottom of the screen succeeds. That is the behaviour these
 * tests exist to pin — a step-driven gate would freeze after the first
 * sub-choice, which is exactly the regression the step reduction had to fix.
 */
final class BookingWizardProgressiveRevealTest extends TestCase
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

    public function test_only_the_city_section_is_shown_before_any_choice_is_made(): void
    {
        Livewire::test(BookingWizard::class)
            ->assertSee('Pilih Lokasi')
            ->assertDontSee('Pilih TPU/TPS')
            ->assertDontSee('Pilih Jenis Layanan')
            ->assertDontSee('Pilih Layanan');
    }

    public function test_choosing_a_city_reveals_the_cemetery_section_without_hiding_the_city_section(): void
    {
        Livewire::test(BookingWizard::class)
            ->call('selectCity', LaunchCityCode::JAKARTA)
            ->assertSee('Pilih Lokasi')
            ->assertSee('Pilih TPU/TPS')
            ->assertDontSee('Pilih Jenis Layanan');
    }

    public function test_choosing_a_cemetery_reveals_the_service_type_section(): void
    {
        Livewire::test(BookingWizard::class)
            ->call('selectCity', LaunchCityCode::JAKARTA)
            ->call('selectCemetery', $this->jakartaCemeteryWithoutPackages()->id)
            ->assertSee('Pilih TPU/TPS')
            ->assertSee('Pilih Jenis Layanan')
            ->assertDontSee('Pilih Layanan');
    }

    /**
     * The whole point of the merge: after the three upstream choices, all
     * four sections stand together on one screen with the single "Lanjutkan"
     * that saves them.
     */
    public function test_choosing_a_service_type_reveals_the_services_section_and_all_four_stack(): void
    {
        $component = Livewire::test(BookingWizard::class)
            ->call('selectCity', LaunchCityCode::JAKARTA)
            ->call('selectCemetery', $this->jakartaCemeteryWithoutPackages()->id)
            ->call('selectServiceType', BookingServiceType::NEW_GRAVE);

        $component
            ->assertSee('Pilih Lokasi')
            ->assertSee('Pilih TPU/TPS')
            ->assertSee('Pilih Jenis Layanan')
            ->assertSee('Pilih Layanan')
            ->assertSeeHtml('wire:click="continueFromDiscovery"')
            ->assertSet('currentStep', BookingWizardStep::DISCOVERY);
    }

    /**
     * None of the three sub-choices persists anything, and none of them
     * advances the stepper. Only "Lanjutkan" does — this is the property that
     * makes a `$currentStep`-driven reveal impossible and the local-property
     * one necessary.
     */
    public function test_no_sub_choice_saves_a_draft_or_advances_the_step(): void
    {
        $component = Livewire::test(BookingWizard::class)
            ->call('selectCity', LaunchCityCode::JAKARTA)
            ->call('selectCemetery', $this->jakartaCemeteryWithoutPackages()->id)
            ->call('selectServiceType', BookingServiceType::NEW_GRAVE)
            ->assertSet('currentStep', BookingWizardStep::DISCOVERY)
            ->assertSet('draftId', null);

        $this->assertDatabaseCount('booking_drafts', 0);

        $component->call('continueFromDiscovery');

        $this->assertDatabaseCount('booking_drafts', 1);
        $this->assertSame(BookingWizardStep::CUSTOMER_AND_DECEASED_DATA, $component->get('currentStep'));
    }

    /**
     * Changing the city drops a cemetery chosen under the previous one: the
     * server-side validator rejects a cemetery outside the payload's city, so
     * carrying it forward would reveal the rest of the screen against a pair
     * that can only fail at "Lanjutkan".
     */
    public function test_changing_the_city_takes_the_previous_citys_cemetery_choice_back_down(): void
    {
        Livewire::test(BookingWizard::class)
            ->call('selectCity', LaunchCityCode::JAKARTA)
            ->call('selectCemetery', $this->jakartaCemeteryWithoutPackages()->id)
            ->assertSee('Pilih Jenis Layanan')
            ->call('selectCity', LaunchCityCode::BOGOR)
            ->assertSet('cemeteryId', null)
            ->assertDontSee('Pilih Jenis Layanan');
    }

    /**
     * Screen 2: Ringkasan is a persistent summary card across the whole
     * screen, shown alongside the merged Data Pemesan / Data Almarhum form —
     * not its own page and not a stepper dot any more.
     */
    public function test_ringkasan_stays_visible_alongside_the_data_form_on_screen_2(): void
    {
        $draftId = (string) Livewire::test(BookingWizard::class)
            ->call('selectCity', LaunchCityCode::JAKARTA)
            ->call('selectCemetery', $this->jakartaCemeteryWithoutPackages()->id)
            ->call('selectServiceType', BookingServiceType::NEW_GRAVE)
            ->call('continueFromDiscovery')
            ->get('draftId');

        Livewire::test(BookingWizard::class, ['draftId' => $draftId])
            ->assertSet('currentStep', BookingWizardStep::CUSTOMER_AND_DECEASED_DATA)
            ->assertSee('Ringkasan Pesanan')
            ->assertSee('Data Pemesan')
            ->assertSee('Data Almarhum');
    }
}
