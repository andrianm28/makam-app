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

final class BookingWizardStepsFourAndFiveTest extends TestCase
{
    use RefreshDatabase;

    private function draftAtStep4(): string
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

        return $draft->id;
    }

    public function test_step_4_offers_every_basic_and_additional_service(): void
    {
        $draftId = $this->draftAtStep4();

        $component = Livewire::test(BookingWizard::class, ['draftId' => $draftId]);

        foreach (['DOCUMENT_PROCESSING', 'GRAVE_DIGGING', 'AMBULANCE', 'FUNERAL_HOME', 'HEARSE', 'TENT_AND_CHAIRS', 'SOUND_SYSTEM', 'FLOWERS', 'GRAVESTONE', 'DOCUMENTATION', 'CATERING', 'LIVE_STREAMING'] as $code) {
            $component->assertSee($code);
        }
    }

    public function test_saving_step_4_with_both_basics_advances_to_step_5(): void
    {
        $draftId = $this->draftAtStep4();

        Livewire::test(BookingWizard::class, ['draftId' => $draftId])
            ->call('saveStep4', [
                ['code' => 'DOCUMENT_PROCESSING', 'quantity' => 1],
                ['code' => 'GRAVE_DIGGING', 'quantity' => 1],
            ])
            ->assertSet('currentStep', BookingWizardStep::SUMMARY);
    }

    /**
     * R3 (pre-flight ruling, human-confirmed 08 Aug 2026): the canonical
     * seed migration (`2026_07_26_220000_seed_service_definition_dummy_
     * operational_data.php`) seeds a real `price_versions` row for every
     * one of the 12 catalogue services, including `DOCUMENT_PROCESSING`
     * (350000.00) and `GRAVE_DIGGING` (750000.00) — so Step 5 with only
     * the two mandatory basics selected always has both prices available
     * in this environment; the honest "Harga belum tersedia" path is
     * never reachable from this fixture and is NOT what this test should
     * assert. That degraded state is already covered by Task 4's own
     * dedicated fixture (`BookingDraftQueryTest::
     * test_summary_marks_a_missing_price_honestly_instead_of_fabricating_a_total`,
     * which supersedes the seeded price before asserting) — this test
     * instead proves the real, seeded happy path renders a correct total.
     */
    public function test_step_5_shows_the_real_computed_total_from_seeded_prices(): void
    {
        $draftId = $this->draftAtStep4();

        Livewire::test(BookingWizard::class, ['draftId' => $draftId])
            ->call('saveStep4', [
                ['code' => 'DOCUMENT_PROCESSING', 'quantity' => 1],
                ['code' => 'GRAVE_DIGGING', 'quantity' => 1],
            ])
            ->assertSee('Rp 350.000')
            ->assertSee('Rp 750.000')
            ->assertSee('Rp 1.100.000')
            ->assertDontSee('Harga belum tersedia');
    }

    public function test_the_autosave_indicator_shows_saved_after_a_successful_step_save(): void
    {
        Livewire::test(BookingWizard::class)
            ->call('saveStep1', LaunchCityCode::JAKARTA)
            ->assertSet('autosaveState', 'saved');
    }

    public function test_the_autosave_indicator_shows_failed_after_a_rejected_step(): void
    {
        Livewire::test(BookingWizard::class)
            ->call('saveStep1', '')
            ->assertSet('autosaveState', 'failed');
    }
}
