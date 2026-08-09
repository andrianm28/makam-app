<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Public\Booking;

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
     * The labels asserted here are `<x-mk.stepper>`'s OWN normative default
     * (design-system.md §3.9), NOT `BookingWizardStep::LABELS`. This test
     * previously asserted the latter, which only passed because the wizard
     * was passing `:labels="$stepLabels"` into the stepper — the exact thing
     * stepper.blade.php's file header, AGENTS.md, and design-system.md §9.2
     * MUST-NOT 9 forbid a booking screen from doing. The assertion, not the
     * behaviour, was wrong: the stepper's dot labels are a presentation
     * contract owned by the primitive.
     *
     * `BookingWizardStep::LABELS` is still correct and still used — it is
     * `booking-wizard-fields.md`'s own step HEADINGS, which this screen
     * renders as its per-step `<h2>`; the two are separate contracts.
     */
    public function test_the_nine_step_stepper_is_always_shown(): void
    {
        $component = Livewire::test(BookingWizard::class);

        foreach ([
            'Lokasi',
            'TPU/TPS',
            'Jenis Layanan',
            'Pilih Layanan',
            'Ringkasan',
            'Data Pemesan',
            'Data Almarhum + Dokumen',
            'Pembayaran',
            'Konfirmasi',
        ] as $label) {
            $component->assertSee($label);
        }
    }

    public function test_the_wizard_never_re_labels_the_stepper(): void
    {
        // The regression this file previously encoded as correct behaviour.
        // Five of the nine BookingWizardStep::LABELS values differ from the
        // stepper's canonical wording; two of those differences are visible
        // only in the stepper's own rail, so asserting their ABSENCE is what
        // proves `:labels` is no longer being passed.
        Livewire::test(BookingWizard::class)
            ->assertDontSee('Data Almarhum and Documents')
            ->assertDontSee('Ringkasan Pesanan');
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

    public function test_selecting_a_city_creates_a_draft_and_redirects_to_its_resume_url(): void
    {
        Livewire::test(BookingWizard::class)
            ->call('saveStep1', LaunchCityCode::JAKARTA)
            ->assertRedirect();

        $this->assertDatabaseCount('booking_drafts', 1);
    }

    public function test_an_invalid_step_1_submission_shows_a_field_error_and_creates_no_draft(): void
    {
        Livewire::test(BookingWizard::class)
            ->call('saveStep1', '')
            ->assertHasErrors(['city_code']);

        $this->assertDatabaseCount('booking_drafts', 0);
    }

    public function test_resuming_via_the_draft_url_continues_at_the_saved_step(): void
    {
        $cemetery = Cemetery::query()->where('city', LaunchCityCode::JAKARTA)->where('publication_status', CemeteryPublicationStatus::PUBLISHED)->firstOrFail();

        $component = Livewire::test(BookingWizard::class)->call('saveStep1', LaunchCityCode::JAKARTA);
        $draftId = $component->get('draftId');

        $this->get("/pemesanan-makam/draft/{$draftId}")->assertOk();

        Livewire::test(BookingWizard::class, ['draftId' => $draftId])
            ->assertSet('city', LaunchCityCode::JAKARTA)
            ->assertSee($cemetery->name);
    }

    public function test_an_unknown_draft_id_falls_back_to_a_fresh_step_1_instead_of_404ing(): void
    {
        Livewire::test(BookingWizard::class, ['draftId' => '00000000-0000-0000-0000-000000000000'])
            ->assertOk()
            ->assertSet('draftId', null);
    }

    public function test_back_navigation_preserves_previously_entered_data(): void
    {
        $component = Livewire::test(BookingWizard::class)->call('saveStep1', LaunchCityCode::JAKARTA);
        $draftId = $component->get('draftId');

        Livewire::test(BookingWizard::class, ['draftId' => $draftId])
            ->call('goToStep', BookingWizardStep::LOCATION)
            ->assertSet('city', LaunchCityCode::JAKARTA);
    }

    public function test_a_failed_cemetery_read_degrades_honestly_instead_of_500ing(): void
    {
        // The draft must be created BEFORE the directory is taken down:
        // `booking_drafts` FK-references `cemetery_packages`/`cemeteries`, so
        // SQLite rejects the step-1 INSERT once those tables are gone
        // ("no such table: main.cemetery_packages"). This is a render-time
        // degradation test, not a write-path test.
        $component = Livewire::test(BookingWizard::class)->call('saveStep1', LaunchCityCode::JAKARTA);
        $draftId = $component->get('draftId');

        // Drop the draft's own FK constraints before taking the directory
        // tables away. On PostgreSQL, `DROP TABLE` of a parent is blocked by
        // any incoming FK constraint (2BP01) regardless of its ON DELETE
        // action — `nullOnDelete` only applies to row DELETEs, never to
        // DROP TABLE. `Schema::disableForeignKeyConstraints()` cannot help
        // here: on Postgres it compiles to `SET CONSTRAINTS ALL DEFERRED`,
        // which only affects DEFERRABLE constraints, and Laravel creates
        // these as NOT DEFERRABLE. Dropping the two constraints is the
        // cross-engine-safe way to make the directory unreadable while the
        // draft row survives.
        Schema::table('booking_drafts', function (Blueprint $table) {
            $table->dropForeign(['cemetery_id']);
            $table->dropForeign(['cemetery_package_id']);
        });

        // Same reverse-dependency drop list as RenewalStartTest's
        // degradation test.
        Schema::dropIfExists('grave_records');
        Schema::dropIfExists('cemetery_packages');
        Schema::dropIfExists('cemetery_capability_profiles');
        Schema::dropIfExists('cemeteries');

        // Resuming the draft must still render honestly (unavailable state),
        // never a 500.
        Livewire::test(BookingWizard::class, ['draftId' => $draftId])
            ->assertOk();
    }
}
