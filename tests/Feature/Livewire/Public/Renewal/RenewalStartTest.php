<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Public\Renewal;

use App\Domain\CemeteryDirectory\CemeteryPublicationStatus;
use App\Domain\CemeteryDirectory\LaunchCityCode;
use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Domain\Renewal\RenewalJourneyStep;
use App\Livewire\Public\Renewal\RenewalStart;
use App\Platform\FeatureGate\Models\FeatureGate;
use App\Support\ExampleData\CemeteryExampleData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * `App\Livewire\Public\Renewal\RenewalStart` — screen PUB-030, Sprint 4
 * S4-T7. `.kiro/specs/renewal-and-grave-registry` AC1 (steps 1-2 of six)
 * and AC2 (the five MVP launch cities).
 *
 * `Livewire::test()` rather than `$this->get('/perpanjangan')`: this
 * component replaces the `RenewalComingSoon` stub, and `routes/web.php` is
 * a shared file this batch does not own — the exact route lines are
 * reported to the batch lead instead. **The route, its name, and its HTTP
 * status are therefore NOT TESTED here**: `routes/web.php` now routes
 * `/perpanjangan` to this component, but that registration is not asserted
 * in this file. See `GraveSearchStatesTest`'s doc block for the same note.
 */
final class RenewalStartTest extends TestCase
{
    use RefreshDatabase;

    // =====================================================================
    // AC2 — the five launch cities
    // =====================================================================

    /**
     * `AGENTS.md` §Mandatory MVP UX: "Launch locations include Jakarta,
     * Bogor, Depok, Tangerang, and Bekasi." The directory spec names
     * "no hidden omission of a required MVP city" as a negative criterion,
     * so this asserts presence AND order.
     */
    public function test_all_five_launch_cities_are_offered_in_the_canonical_order(): void
    {
        $component = Livewire::test(RenewalStart::class);

        $html = $component->html();

        $positions = [];

        foreach (['Jakarta', 'Bogor', 'Depok', 'Tangerang', 'Bekasi'] as $label) {
            $position = strpos($html, $label);
            $this->assertNotFalse($position, "Expected launch city [{$label}] to be offered.");
            $positions[] = $position;
        }

        $sorted = $positions;
        sort($sorted);

        $this->assertSame($sorted, $positions, 'Launch cities must render in LaunchCityCode order.');
    }

    /**
     * The city list is derived from `LaunchCityCode`, so it can never
     * silently shrink to "cities that happen to have a published
     * cemetery". Proven by removing every published cemetery and checking
     * the city is still offered.
     */
    public function test_a_city_with_no_published_cemetery_is_still_offered(): void
    {
        Cemetery::query()->where('city', LaunchCityCode::BEKASI)->update(['publication_status' => CemeteryPublicationStatus::DRAFT]);

        Livewire::test(RenewalStart::class)->assertSee('Bekasi');
    }

    // =====================================================================
    // AC1 step 2 — TPU/TPS list
    // =====================================================================

    public function test_selecting_a_city_lists_its_published_cemeteries(): void
    {
        Livewire::test(RenewalStart::class)
            ->call('selectCity', LaunchCityCode::JAKARTA)
            ->assertSee($this->firstPublishedExampleName(LaunchCityCode::JAKARTA))
            // A different city's cemeteries must not leak into the list.
            ->assertDontSee($this->firstPublishedExampleName(LaunchCityCode::BOGOR));
    }

    /**
     * `CemeteryExampleData::DRAFT_SLUG` is the deliberately-`draft` example
     * cemetery. Excluding it is real production behaviour from
     * `Cemetery::scopePublished()`, which `CemeteryPublicQuery` composes
     * rather than works around.
     */
    public function test_a_draft_cemetery_is_never_offered(): void
    {
        Livewire::test(RenewalStart::class)
            ->call('selectCity', LaunchCityCode::BEKASI)
            ->assertSee($this->firstPublishedExampleName(LaunchCityCode::BEKASI))
            ->assertDontSee(CemeteryExampleData::bySlug(CemeteryExampleData::DRAFT_SLUG)[1]);
    }

    /** @return string the first published example cemetery's name in $city. */
    private function firstPublishedExampleName(string $city): string
    {
        foreach (CemeteryExampleData::cemeteries() as [$type, $name, $slug, $cemeteryCity, $address, $operatorName, $facilities, $publicationStatus]) {
            if ($cemeteryCity === $city && $publicationStatus === CemeteryPublicationStatus::PUBLISHED) {
                return $name;
            }
        }

        $this->fail("No published example cemetery exists for city [{$city}].");
    }

    /**
     * §6.2 — three parts (what is empty, why, what to do next), never a
     * bare "Tidak ada data". Reached by unpublishing Bekasi's one published
     * cemetery, which exercises the real draft-exclusion path rather than a
     * fabricated fixture.
     */
    public function test_a_city_with_no_published_cemetery_renders_a_three_part_empty_state(): void
    {
        Cemetery::query()->where('city', LaunchCityCode::BEKASI)->update(['publication_status' => CemeteryPublicationStatus::DRAFT]);

        Livewire::test(RenewalStart::class)
            ->call('selectCity', LaunchCityCode::BEKASI)
            // 1. What is empty.
            ->assertSee('Belum ada TPU/TPS terdaftar di Bekasi.')
            // 2. Why — explicitly not "there are no cemeteries in Bekasi".
            ->assertSee('belum lengkap di sistem kami')
            // 3. What to do next.
            ->assertSee('Hubungi Bantuan');
    }

    public function test_an_unknown_city_code_is_discarded_rather_than_404ing(): void
    {
        Livewire::withQueryParams(['kota' => 'SURABAYA'])
            ->test(RenewalStart::class)
            ->assertOk()
            ->assertSet('city', '')
            ->assertSee('Pilih kota terlebih dahulu');
    }

    /**
     * mount()'s guard runs once; a client-initiated property update
     * re-hydrates without re-running it, so before the fix an unknown
     * `$city` pushed currentStep() to 2 while $selectedCityLabel stayed
     * null and the 6.2 empty state degraded to "Belum ada TPU/TPS
     * terdaftar di ." — losing the "what is empty" part its three-part
     * contract requires. Normalising in render() subsumes every update
     * path. The final assertDontSee pins the actual user-visible defect
     * and must not be dropped as redundant.
     */
    public function test_a_client_supplied_unknown_city_is_discarded_on_update_not_only_on_mount(): void
    {
        Livewire::test(RenewalStart::class)
            ->set('city', 'SURABAYA')
            ->assertSet('city', '')
            ->assertSee('Pilih kota terlebih dahulu')
            ->assertDontSee('Belum ada TPU/TPS terdaftar di .');
    }

    public function test_select_city_refuses_a_code_outside_the_launch_list(): void
    {
        Livewire::test(RenewalStart::class)
            ->call('selectCity', 'SURABAYA')
            ->assertSet('city', '');
    }

    // =====================================================================
    // AC1 — the six-step stepper
    // =====================================================================

    public function test_the_stepper_shows_this_journeys_six_steps_not_the_nine_booking_ones(): void
    {
        $component = Livewire::test(RenewalStart::class);

        foreach (['Kota', 'TPU/TPS', 'Cari Makam', 'Biaya', 'Pembayaran', 'Konfirmasi'] as $label) {
            $component->assertSee($label);
        }

        foreach (['Jenis Layanan', 'Data Almarhum + Dokumen', 'Data Pemesan'] as $bookingOnlyLabel) {
            $component->assertDontSee($bookingOnlyLabel);
        }
    }

    /**
     * The current step is DERIVED from whether a city is selected, not
     * tracked as a second piece of state that could drift out of sync.
     */
    public function test_the_current_step_advances_from_one_to_two_when_a_city_is_chosen(): void
    {
        Livewire::test(RenewalStart::class)
            ->assertSee('Langkah 1 dari 6')
            ->call('selectCity', LaunchCityCode::JAKARTA)
            ->assertSee('Langkah 2 dari 6');
    }

    /**
     * `<x-mk.stepper>` defaults its click target to `goToStep`
     * (design-system.md §3.9), so once step 1 renders as `complete` the
     * dot becomes a live button calling a method THIS component must
     * implement. The first assertion pins the seam — that the stepper
     * still emits the default method name and that this component is the
     * thing that must answer it; without it the test would pass even if
     * the stepper stopped emitting the button, which is the drift that
     * produced the original dead control. The last assertion pins that an
     * unreachable step is a silent no-op and never a 500.
     */
    public function test_the_completed_step_one_dot_calls_a_method_this_component_implements(): void
    {
        $component = Livewire::test(RenewalStart::class);
        $component->call('selectCity', LaunchCityCode::JAKARTA);

        $component->assertSee('wire:click="goToStep(1)"', false);

        $component->call('goToStep', RenewalJourneyStep::FEE)
            ->assertOk()
            ->assertSet('city', LaunchCityCode::JAKARTA)
            ->assertSee('Langkah 2 dari 6');

        $component->call('goToStep', RenewalJourneyStep::CITY)
            ->assertSet('city', '')
            ->assertSee('Langkah 1 dari 6');
    }

    /**
     * The "Ganti kota" control is a <x-mk.button variant="link"> (design-
     * system.md 9.2 MUST #2 — the page header claims every button uses the
     * primitive, and this was the one hand-forked holdout). It renders only
     * once a city is selected. This test pins the behaviour across the swap;
     * primitive conformance itself is verified by reading, not by a test —
     * no CI gate enforces 9.2 MUST #2.
     */
    public function test_the_change_city_control_returns_to_step_one(): void
    {
        Livewire::test(RenewalStart::class)
            ->call('selectCity', LaunchCityCode::JAKARTA)
            ->assertSee('Ganti kota')
            ->assertSet('city', LaunchCityCode::JAKARTA)
            ->call('resetCity')
            ->assertSet('city', '');
    }

    // =====================================================================
    // AC16 / §6.9 — the gate banner
    // =====================================================================

    /**
     * `G-DATA-01` seeds closed, so the banner is the default. Read from the
     * real seeded row rather than assumed, the same discipline
     * `HomePageRouteTest` applies to `G-OPS-01`.
     */
    public function test_the_closed_data_gate_renders_an_honest_banner_without_removing_the_step(): void
    {
        $gate = FeatureGate::query()->where('gate_id', 'G-DATA-01')->first();
        $this->assertNotNull($gate);
        $this->assertSame('closed', $gate->state);

        Livewire::test(RenewalStart::class)
            ->assertSee('Pencarian Data Makam Belum Tersedia Online')
            // design-system.md §9.2 MUST NOT 9: a documented step is never
            // hidden — city and cemetery selection must still work.
            ->assertSee('Pilih Kota')
            ->assertSee('Jakarta')
            // Never an implication that the record does not exist.
            ->assertSee('Ini bukan berarti data makam yang Anda cari tidak ada.');
    }

    public function test_opening_the_data_gate_removes_the_banner(): void
    {
        FeatureGate::query()->where('gate_id', 'G-DATA-01')->update(['state' => 'open']);

        Livewire::test(RenewalStart::class)
            ->assertDontSee('Pencarian Data Makam Belum Tersedia Online')
            // design-system.md §9.2 MUST NOT 9: the gate state changes the
            // banner, never the journey — city and cemetery selection must
            // survive BOTH gate states, and the closed-gate test asserts
            // them while this one asserts them with the gate open.
            ->assertSee('Pilih Kota')
            ->assertSee('Jakarta');
    }

    // =====================================================================
    // §6.5 — provider unavailable
    // =====================================================================

    /**
     * The cemetery list is a SECONDARY read. When it fails, step 1 (built
     * from a PHP constant with no database behind it) must keep working and
     * the page must not 500 — design-system.md §6.3/§6.5, the same
     * guarantee `HomePage` and `FaqIndex` implement.
     *
     * Forced with a real dropped table rather than a mocked query class.
     * The children are dropped first, in reverse-dependency order, instead
     * of using `DROP TABLE ... CASCADE`: SQLite's `DROP TABLE` has no
     * CASCADE clause, and `phpunit.xml` defaults to SQLite locally while CI
     * runs PostgreSQL. If a later batch adds another table with a foreign
     * key to `cemeteries`, this test fails with an FK error rather than
     * passing silently — which is the correct signal, not a flaw. (That
     * tripwire is exactly what caught the `booking_drafts` FK added on
     * 08 Aug 2026 and the four P4 visitation tables added on 16 Aug 2026;
     * each batch's tables join the drop list below, so the tripwire still
     * guards every table added in the future.)
     *
     * Safe: RefreshDatabase rolls the whole test transaction back.
     */
    public function test_a_failed_cemetery_read_degrades_honestly_instead_of_500ing(): void
    {
        // Empty in this test — no draft is ever created. Dropped first so
        // PostgreSQL's `DROP TABLE` of the parents is not blocked by the
        // incoming `booking_drafts` FK (2BP01); its `nullOnDelete` only
        // applies to row DELETEs, never to DROP TABLE.
        //
        // Order-orchestration tables FK-referencing `booking_drafts` /
        // `orders` / `quotes` (Task 3/4/5, 12 Aug 2026) are dropped first so
        // PostgreSQL's `DROP TABLE` of `booking_drafts` is not blocked by the
        // incoming FKs (2BP01) — the same tripwire this comment documents.
        // P4 visitation tables (16 Aug 2026) come first of all: `visitation_
        // bookings` FK-references `cemeteries`/`cemetery_visitation_policies`,
        // `visitation_date_capacities` and `visitation_blackout_dates` FK-
        // reference `cemetery_visitation_policies`, and
        // `cemetery_visitation_policies` FK-references `cemeteries` —
        // PostgreSQL blocks `DROP TABLE` of each parent by ANY incoming FK
        // (2BP01), so all four must precede `cemeteries` below. P3 plot
        // tables (16 Aug 2026) come before their parents too:
        // `plot_reservations` FK-references `grave_plots` and `orders`,
        // `grave_plots` FK-references `cemetery_blocks`/`cemetery_packages`,
        // and `cemetery_blocks` FK-references `cemeteries` — PostgreSQL
        // blocks `DROP TABLE` of each parent by ANY incoming FK (2BP01).
        // P5a pre-need tables (16 Aug 2026) come first of all:
        // `pre_need_consultation_requests` FK-references `pre_need_interests`
        // (nullable, nullOnDelete), so it must precede that table below;
        // `pre_need_payment_schedules` FK-references `pre_need_cases`
        // (restrictOnDelete), and `pre_need_cases` FK-references
        // `quotes`/`plot_reservations`/`funeral_cases`/
        // `cemetery_packages`/`cemeteries`, so both must precede their
        // parents below (2BP01).
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
        Schema::dropIfExists('quote_lines');
        Schema::dropIfExists('order_documents');
        Schema::dropIfExists('order_status_events');
        Schema::dropIfExists('order_parties');
        Schema::dropIfExists('deceased_profiles');
        Schema::dropIfExists('quotes');
        Schema::dropIfExists('funeral_cases');
        Schema::dropIfExists('pre_need_interests');
        Schema::dropIfExists('order_invoices');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('booking_drafts');
        Schema::dropIfExists('renewal_external_markings');
        Schema::dropIfExists('renewal_quotes');
        Schema::dropIfExists('renewals');
        Schema::dropIfExists('grave_records');
        Schema::dropIfExists('cemetery_packages');
        Schema::dropIfExists('cemetery_capability_profiles');
        Schema::dropIfExists('cemeteries');

        Livewire::test(RenewalStart::class)
            ->call('selectCity', LaunchCityCode::JAKARTA)
            ->assertOk()
            // Step 1 still works — it never touched the database.
            ->assertSee('Jakarta')
            ->assertSee('Bogor')
            // Said plainly, and distinct from "this city has no TPU/TPS".
            ->assertSee('Daftar TPU/TPS sedang tidak dapat dimuat')
            ->assertDontSee('Belum ada TPU/TPS terdaftar');
    }

    // =====================================================================
    // §6.10 — support escape hatch
    // =====================================================================

    public function test_the_support_escape_hatch_is_present(): void
    {
        Livewire::test(RenewalStart::class)
            ->assertSee('/bantuan')
            ->assertSee('Hubungi Bantuan');
    }
}
