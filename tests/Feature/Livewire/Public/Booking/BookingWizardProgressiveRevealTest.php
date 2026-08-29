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
 * Screen 1 ("Cari & Pilih") stacks steps 1-4 in one continuous scroll as
 * each becomes valid-and-saved, per `docs/superpowers/specs/
 * 2026-08-29-wizard-screen-consolidation-design.md`. The stepper's active
 * dot still advances 1..9 exactly as before — only how many sections are
 * simultaneously ON SCREEN changes.
 */
final class BookingWizardProgressiveRevealTest extends TestCase
{
    use RefreshDatabase;

    public function test_step_2_is_not_shown_before_step_1_is_saved(): void
    {
        // The bare substring "Langkah 2" is not a safe marker on its own:
        // <x-mk.stepper> renders an sr-only "Langkah {n}: {label} (belum
        // tersedia)" label for every dot on every step, unconditionally —
        // predating this task entirely (stepper.blade.php lines 222/232/242).
        // The section-heading text (with its literal "&mdash;", never used
        // by the stepper's own colon-separated sr-only format) is the
        // marker that actually discriminates the step-2 SECTION from the
        // stepper's always-present accessible labels.
        Livewire::test(BookingWizard::class)
            ->assertSee('Langkah 1')
            ->assertDontSee('Langkah 2 &mdash; Pilih TPU/TPS', false);
    }

    public function test_completing_step_1_reveals_step_2_without_hiding_step_1(): void
    {
        // Two component instances, not assertions chained straight off the
        // saveStep1() call: saveStep1() redirects on success
        // (`$this->redirect(..., navigate: false)`), and Livewire's
        // HandlesRedirects::redirect() calls skipRender() whenever
        // `livewire.render_on_redirect` is false (this app's default) — so
        // ->html()/assertSee() on THAT SAME Testable instance would still
        // reflect the pre-save, mount-time markup even though the
        // component's own currentStep/completedSteps are already correct.
        // A fresh mount by draftId (as the next test already does) reads
        // the real, saved state instead.
        $draftId = Livewire::test(BookingWizard::class)
            ->call('saveStep1', LaunchCityCode::JAKARTA)
            ->get('draftId');

        // Section headings (with their literal "&mdash;"), not the bare
        // "Langkah N" substring — see the previous test's comment on why
        // that substring alone never proves a section is visible.
        Livewire::test(BookingWizard::class, ['draftId' => $draftId])
            ->assertSee('Langkah 1 &mdash; Pilih Lokasi', false)
            ->assertSee('Langkah 2 &mdash; Pilih TPU/TPS', false);
    }

    /**
     * The whole reason for this consolidation: after completing steps 1-3,
     * all three of their sections remain visible together with step 4's,
     * in one screen — not replaced one at a time.
     */
    public function test_all_four_screen_1_sections_stack_once_step_3_is_saved(): void
    {
        $draftId = Livewire::test(BookingWizard::class)
            ->call('saveStep1', LaunchCityCode::JAKARTA)
            ->get('draftId');

        $cemetery = Cemetery::query()
            ->where('city', LaunchCityCode::JAKARTA)
            ->where('publication_status', 'published')
            ->whereDoesntHave('packages')
            ->firstOrFail();

        $component = Livewire::test(BookingWizard::class, ['draftId' => $draftId])
            ->call('saveStep2', $cemetery->id)
            ->call('saveStep3', 'NEW_GRAVE');

        $component
            ->assertSee('Langkah 1 &mdash; Pilih Lokasi', false)
            ->assertSee('Langkah 2 &mdash; Pilih TPU/TPS', false)
            ->assertSee('Langkah 3 &mdash; Pilih Jenis Layanan', false)
            ->assertSee('Langkah 4 &mdash; Pilih Layanan', false);

        $this->assertSame(1, $component->instance()->currentScreen());
    }

    /**
     * The stepper's own dot still advances one at a time as sections
     * reveal within Screen 1 — the consolidation changes what wraps the
     * dots, never the dots themselves (design-system.md §9.2 MUST-NOT-9).
     */
    public function test_the_stepper_dot_still_advances_one_step_at_a_time_within_screen_1(): void
    {
        $draftId = Livewire::test(BookingWizard::class)
            ->call('saveStep1', LaunchCityCode::JAKARTA)
            ->get('draftId');

        Livewire::test(BookingWizard::class, ['draftId' => $draftId])
            ->assertSet('currentStep', BookingWizardStep::CEMETERY)
            ->call('saveStep2', Cemetery::query()
                ->where('city', LaunchCityCode::JAKARTA)
                ->where('publication_status', 'published')
                ->whereDoesntHave('packages')
                ->firstOrFail()->id)
            ->assertSet('currentStep', BookingWizardStep::SERVICE_TYPE);
    }

    /**
     * Screen 2: Ringkasan is a persistent summary card across the whole
     * screen, visible alongside Data Pemesan and Data Almarhum once they
     * reveal — not its own page (spec's Screen 2 row).
     */
    public function test_ringkasan_stays_visible_alongside_data_pemesan_on_screen_2(): void
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
        $draft = (new SaveBookingDraftStep)($draft, BookingWizardStep::SERVICES, [
            'selected_services' => [
                ['code' => 'DOCUMENT_PROCESSING', 'quantity' => 1],
                ['code' => 'GRAVE_DIGGING', 'quantity' => 1],
            ],
        ], 'idem-d');

        Livewire::test(BookingWizard::class, ['draftId' => $draft->id])
            ->call('goToStep', BookingWizardStep::CUSTOMER_DATA)
            ->assertSee('Ringkasan Pesanan')
            ->assertSee('Data Pemesan');
    }
}
