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

    /**
     * Step 3 used to render the raw `BookingServiceType` codes to the user.
     * The labels come from `mvp-scope.md` row 3 / `product-brief.md` §3
     * ("Makam Baru, Makam Tumpang, Urgent, Pre-Need") — copied, not invented.
     */
    public function test_step_3_shows_product_labels_not_raw_enum_codes(): void
    {
        $cemetery = Cemetery::query()
            ->where('city', LaunchCityCode::JAKARTA)
            ->where('publication_status', 'published')
            ->whereDoesntHave('packages')
            ->firstOrFail();

        $draftId = Livewire::test(BookingWizard::class)
            ->call('saveStep1', LaunchCityCode::JAKARTA)
            ->get('draftId');

        $component = Livewire::test(BookingWizard::class, ['draftId' => $draftId])
            ->call('saveStep2', $cemetery->id)
            ->assertSet('currentStep', BookingWizardStep::SERVICE_TYPE);

        foreach (BookingServiceType::LABELS as $code => $label) {
            // `<x-mk.button>` renders its slot inside a bare `<span>`, so
            // this asserts the VISIBLE copy specifically, not merely that
            // the string occurs somewhere in the markup.
            $component->assertSeeHtml("<span>{$label}</span>");
            $component->assertDontSeeHtml("<span>{$code}</span>");
            // The code survives only inside the `wire:click` payload — it is
            // still what `saveStep3()` receives and what is persisted.
            $component->assertSeeHtml("saveStep3('{$code}')");
        }
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

    /**
     * Asserts `<x-mk.stepper>`'s own canonical labels (design-system.md
     * §3.9), not `BookingWizardStep::LABELS` — see
     * `BookingWizardRouteTest::test_the_nine_step_stepper_is_always_shown`
     * for why that swap is a corrected expectation rather than a weakened
     * one. What this test is really about is unchanged: the full nine-step
     * journey is always visible, including Steps 6-9 (which have real
     * screens behind them since 13 Aug 2026 — L6's `saveStep6/7/8` and the
     * confirmation read model).
     */
    public function test_the_stepper_never_removes_steps_6_through_9(): void
    {
        $component = Livewire::test(BookingWizard::class);

        foreach (['Data Pemesan', 'Data Almarhum + Dokumen', 'Pembayaran', 'Konfirmasi'] as $stepLabel) {
            $component->assertSee($stepLabel);
        }
    }
}
