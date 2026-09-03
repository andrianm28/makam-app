<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Public\Booking;

use App\Domain\Booking\BookingServiceType;
use App\Domain\Booking\BookingWizardScreen;
use App\Domain\Booking\BookingWizardStep;
use App\Domain\CemeteryDirectory\CemeteryPublicationStatus;
use App\Domain\CemeteryDirectory\LaunchCityCode;
use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Livewire\Public\Booking\BookingWizard;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\TestCase;

final class BookingWizardRouteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // `test_the_route_replaces_the_coming_soon_stub` renders the full
        // layout (@vite(...) in layouts/app.blade.php); this host's CI `php`
        // job has no frontend build. Same requirement/reasoning as every
        // other public Livewire route test in this repo (e.g.
        // CemeteryDirectoryIndexRouteTest).
        $this->withoutVite();
    }

    public function test_the_route_replaces_the_coming_soon_stub(): void
    {
        $this->get('/pemesanan-makam')->assertOk()->assertSeeLivewire(BookingWizard::class);
    }

    /**
     * The stepper's dot labels are `BookingWizardScreen::labels()` — the four
     * SCREEN names the wizard was reduced to
     * (`docs/superpowers/specs/2026-09-02-wizard-step-reduction-design.md`).
     *
     * This test used to assert `<x-mk.stepper>`'s own nine-label default and
     * to treat passing `:labels` from booking as the bug. That was correct
     * while booking really had nine steps: the default WAS the canonical
     * booking journey. It no longer is — the wizard has four steps, so
     * omitting `:labels` would render five dots the customer can never reach.
     * The primitive's default is now the stale value and the explicit array
     * is the canonical one; §9.2 MUST-NOT 9's prohibition on re-labelling a
     * booking step is unaffected, because this is the step vocabulary itself
     * changing, not a screen rewording it locally.
     */
    public function test_the_four_screen_stepper_is_always_shown(): void
    {
        $component = Livewire::test(BookingWizard::class);

        foreach (BookingWizardScreen::LABELS as $label) {
            $component->assertSee($label);
        }
    }

    public function test_the_wizard_no_longer_renders_the_old_nine_step_labels(): void
    {
        // The five old step labels that are not a substring of any current
        // one, so their absence really does prove the nine-dot rail is gone
        // rather than merely being reworded.
        Livewire::test(BookingWizard::class)
            ->assertDontSee('Data Almarhum + Dokumen')
            ->assertDontSee('Ringkasan')
            ->assertDontSee('Pilih Layanan')
            ->assertDontSee('Jenis Layanan');
    }

    public function test_step_1_offers_all_five_launch_cities_in_order(): void
    {
        $component = Livewire::test(BookingWizard::class);
        $html = $component->html();

        $positions = [];
        foreach (['Jakarta', 'Bogor', 'Depok', 'Tangerang', 'Bekasi'] as $label) {
            $position = strpos($html, $label);
            $this->assertNotFalse($position);
            $positions[] = $position;
        }

        $sorted = $positions;
        sort($sorted);
        $this->assertSame($sorted, $positions);
    }

    public function test_no_draft_is_created_merely_by_viewing_the_page(): void
    {
        $this->assertDatabaseCount('booking_drafts', 0);

        Livewire::test(BookingWizard::class);

        $this->assertDatabaseCount('booking_drafts', 0);
    }

    /**
     * Selecting a city is now pure UI state — `selectCity()` persists
     * nothing. Under the merge the ONE DISCOVERY save happens at the bottom
     * of the screen, so a customer who picks a city and leaves creates no
     * row at all (the plot picker is the one earlier draft-creating path,
     * covered by `BookingWizardPlotPickerTest`).
     */
    public function test_selecting_a_city_reveals_the_cemetery_section_without_creating_a_draft(): void
    {
        Livewire::test(BookingWizard::class)
            ->call('selectCity', LaunchCityCode::JAKARTA)
            ->assertSet('city', LaunchCityCode::JAKARTA)
            ->assertSee('Pilih TPU/TPS');

        $this->assertDatabaseCount('booking_drafts', 0);
    }

    public function test_completing_discovery_creates_a_draft_and_redirects_to_its_resume_url(): void
    {
        $cemetery = Cemetery::query()
            ->where('city', LaunchCityCode::JAKARTA)
            ->where('publication_status', CemeteryPublicationStatus::PUBLISHED)
            ->whereDoesntHave('packages')
            ->firstOrFail();

        Livewire::test(BookingWizard::class)
            ->call('selectCity', LaunchCityCode::JAKARTA)
            ->call('selectCemetery', $cemetery->id)
            ->call('selectServiceType', BookingServiceType::NEW_GRAVE)
            ->call('continueFromDiscovery')
            ->assertRedirect();

        $this->assertDatabaseCount('booking_drafts', 1);
    }

    public function test_an_invalid_discovery_submission_shows_a_field_error_and_creates_no_draft(): void
    {
        Livewire::test(BookingWizard::class)
            ->call('continueFromDiscovery')
            ->assertHasErrors(['city_code']);

        $this->assertDatabaseCount('booking_drafts', 0);
    }

    public function test_resuming_via_the_draft_url_continues_at_the_saved_step(): void
    {
        $cemetery = Cemetery::query()
            ->where('city', LaunchCityCode::JAKARTA)
            ->where('publication_status', CemeteryPublicationStatus::PUBLISHED)
            ->whereDoesntHave('packages')
            ->firstOrFail();

        $draftId = $this->completeDiscovery($cemetery->id);

        $this->get("/pemesanan-makam/draft/{$draftId}")->assertOk();

        // Screen 2, because DISCOVERY is saved — the cemetery NAME is no
        // longer on the page (that list belongs to screen 1), so what proves
        // the resume is the restored state plus the step it resumes at.
        Livewire::test(BookingWizard::class, ['draftId' => $draftId])
            ->assertSet('city', LaunchCityCode::JAKARTA)
            ->assertSet('cemeteryId', $cemetery->id)
            ->assertSet('currentStep', BookingWizardStep::CUSTOMER_AND_DECEASED_DATA)
            ->assertSee('Data Pemesan');
    }

    public function test_an_unknown_draft_id_falls_back_to_a_fresh_step_1_instead_of_404ing(): void
    {
        Livewire::test(BookingWizard::class, ['draftId' => '00000000-0000-0000-0000-000000000000'])
            ->assertOk()
            ->assertSet('draftId', null);
    }

    public function test_back_navigation_preserves_previously_entered_data(): void
    {
        $cemetery = Cemetery::query()
            ->where('city', LaunchCityCode::JAKARTA)
            ->where('publication_status', CemeteryPublicationStatus::PUBLISHED)
            ->whereDoesntHave('packages')
            ->firstOrFail();

        $draftId = $this->completeDiscovery($cemetery->id);

        // Back to DISCOVERY: every section of that screen reveals again from
        // the restored selections, not just the first one.
        Livewire::test(BookingWizard::class, ['draftId' => $draftId])
            ->call('goToStep', BookingWizardStep::DISCOVERY)
            ->assertSet('city', LaunchCityCode::JAKARTA)
            ->assertSee('Pilih TPU/TPS')
            ->assertSee('Pilih Jenis Layanan')
            ->assertSee('Pilih Layanan');
    }

    private function completeDiscovery(string $cemeteryId): string
    {
        $component = Livewire::test(BookingWizard::class)
            ->call('selectCity', LaunchCityCode::JAKARTA)
            ->call('selectCemetery', $cemeteryId)
            ->call('selectServiceType', BookingServiceType::NEW_GRAVE)
            ->call('continueFromDiscovery');

        return (string) $component->get('draftId');
    }

    public function test_a_failed_cemetery_read_degrades_honestly_instead_of_500ing(): void
    {
        // No draft is built first any more: under the merged DISCOVERY step
        // a draft only exists once the whole screen is saved, and a saved
        // DISCOVERY moves the customer to screen 2, which reads no cemetery
        // at all. The failure under test is the screen-1 TPU/TPS list read,
        // so the fixture is a component whose city is chosen (local UI state,
        // exactly what `selectCity()` sets) with the directory unreadable.
        //
        // `booking_drafts` itself still FK-references the directory tables,
        // and on PostgreSQL an incoming FK blocks `DROP TABLE` of the parent
        // (2BP01) whether or not any row exists, so those two constraints
        // still have to go first.
        Schema::table('booking_drafts', function (Blueprint $table) {
            $table->dropForeign(['cemetery_id']);
            $table->dropForeign(['cemetery_package_id']);
        });

        Schema::dropIfExists('pre_need_consultation_requests');
        Schema::dropIfExists('pre_need_payment_schedules');
        Schema::dropIfExists('pre_need_cases');
        Schema::dropIfExists('visitation_bookings');
        Schema::dropIfExists('visitation_date_capacities');
        Schema::dropIfExists('visitation_blackout_dates');
        Schema::dropIfExists('cemetery_visitation_policies');
        // P4 memorial tables (16 Aug 2026) come before the plot tables:
        // every memorial table FK-references `memorial_profiles`, which
        // FK-references `grave_records` (restrictOnDelete), so all seven
        // must precede `grave_records` below (2BP01).
        Schema::dropIfExists('abuse_reports');
        Schema::dropIfExists('moderation_cases');
        Schema::dropIfExists('memorial_qr_tokens');
        Schema::dropIfExists('memorial_media');
        Schema::dropIfExists('memorial_contents');
        Schema::dropIfExists('memorial_editors');
        Schema::dropIfExists('memorial_profiles');
        Schema::dropIfExists('plot_reservations');
        Schema::dropIfExists('grave_plots');
        Schema::dropIfExists('cemetery_blocks');
        Schema::dropIfExists('renewal_external_markings');
        Schema::dropIfExists('renewal_quotes');
        Schema::dropIfExists('renewals');
        Schema::dropIfExists('grave_records');
        Schema::dropIfExists('cemetery_packages');
        Schema::dropIfExists('cemetery_capability_profiles');
        Schema::dropIfExists('cemeteries');

        // Honest §6.5 "provider unavailable" banner, never a 500 and never a
        // silently empty list that would read as "no TPU/TPS here".
        Livewire::test(BookingWizard::class)
            ->call('selectCity', LaunchCityCode::JAKARTA)
            ->assertOk()
            ->assertSee('Daftar TPU/TPS sedang tidak dapat dimuat');
    }
}
