<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Public\Renewal;

use App\Console\Commands\GenerateGraveRegistryLoadDatasetCommand;
use App\Domain\CemeteryDirectory\CemeteryPublicationStatus;
use App\Domain\CemeteryDirectory\LaunchCityCode;
use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Domain\GraveRegistry\GraveRecordAccessMode;
use App\Domain\GraveRegistry\Models\GraveRecord;
use App\Domain\Renewal\RenewalGraveSelection;
use App\Domain\Renewal\RenewalJourneyStep;
use App\Livewire\Public\Renewal\RenewalStart;
use App\Platform\FeatureGate\Models\FeatureGate;
use App\Support\ExampleData\CemeteryExampleData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\Support\CemeteryFixture;
use Tests\TestCase;

/**
 * `App\Livewire\Public\Renewal\RenewalStart` — Screen 1 "Cari Makam" of the
 * consolidated renewal journey (`docs/superpowers/specs/
 * 2026-08-29-wizard-screen-consolidation-design.md`), merging the former
 * `RenewalStart` (steps 1-2, screen PUB-030) and `GraveSearch` (step 3,
 * screen PUB-031, formerly its own route `/perpanjangan/cari`).
 *
 * The bulk of this file below the AC1/AC2/§6.5/§6.9/§6.10 sections is
 * migrated from `GraveSearchStatesTest` and `GraveSearchPerformanceTest`
 * (deleted in the same commit) — mechanically retargeted to `RenewalStart`
 * with `cemeteryId`/`name`/`block`/`deathDate` passed as `Livewire::test()`
 * constructor properties instead of `Livewire::withQueryParams()`, since
 * mount() reads these BEFORE render() either way and the two are
 * behaviourally equivalent entry points for a component with no other
 * consumer of the query string. See each migrated test's own note for any
 * assertion that had to change because two routes became one progressively
 * revealed screen (the `?tpu=` "orphaned" state in particular — see the
 * "Cemetery scoping" section below).
 *
 * `Livewire::test()` rather than `$this->get('/perpanjangan')`: `routes/
 * web.php` is a shared file; the route, its name, and its HTTP status are
 * NOT TESTED here.
 */
final class RenewalStartTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Copy fragments that identify each of the three grave-search empty
     * states — migrated from `GraveSearchStatesTest`. Asserting one state's
     * fragment ABSENT while another is present is what proves they were not
     * collapsed.
     */
    private const GATE_CLOSED_MARKER = 'Pencarian Data Makam Belum Tersedia';

    private const NO_RESULT_MARKER = 'Data makam tidak ditemukan.';

    private const PRIVACY_LIMITED_MARKER = 'aksesnya dibatasi';

    private function openTheDataGate(): void
    {
        FeatureGate::query()->where('gate_id', 'G-DATA-01')->update(['state' => 'open']);
    }

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

    /**
     * The merged screen's Step 2 section is progressively revealed (only
     * rendered once `$city !== ''`), replacing the pre-merge screen's
     * always-visible "Pilih kota terlebih dahulu untuk melihat daftar
     * TPU/TPS yang tersedia." placeholder — that string no longer exists
     * anywhere in the view. What still must hold, and what this asserts
     * instead: the unknown code is discarded (never trusted) and Step 2
     * simply does not render, rather than 404ing or leaking the bad value.
     */
    public function test_an_unknown_city_code_is_discarded_rather_than_404ing(): void
    {
        Livewire::withQueryParams(['kota' => 'SURABAYA'])
            ->test(RenewalStart::class)
            ->assertOk()
            ->assertSet('city', '')
            ->assertDontSee('Pilih TPU/TPS');
    }

    /**
     * mount()'s guard runs once; a client-initiated property update
     * re-hydrates without re-running it, so before the original fix an
     * unknown `$city` pushed currentStep() to 2 while $selectedCityLabel
     * stayed null and the 6.2 empty state degraded to "Belum ada TPU/TPS
     * terdaftar di ." — losing the "what is empty" part its three-part
     * contract requires. Normalising in render() subsumes every update
     * path. See the previous test's own note for why this now asserts
     * Step 2's absence rather than the pre-merge placeholder text; the
     * final assertDontSee still pins the actual historical defect and
     * must not be dropped as redundant.
     */
    public function test_a_client_supplied_unknown_city_is_discarded_on_update_not_only_on_mount(): void
    {
        Livewire::test(RenewalStart::class)
            ->set('city', 'SURABAYA')
            ->assertSet('city', '')
            ->assertDontSee('Pilih TPU/TPS')
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
     * The same drift-pin as the test above, one dot over — written because
     * the `GraveSearch` merge widened this component from journey steps 1-2
     * to 1-3, so for the first time TWO dots can render as `complete` here
     * at once. Dot 2 (TPU/TPS) then becomes a live
     * `<button wire:click="goToStep(2)">` exactly as dot 1 already was, and
     * `goToStep()`'s allow-list has to have grown with it; it had not, which
     * is precisely the dead-control defect the step-one test above exists to
     * catch. The first assertion pins the seam (the stepper really does emit
     * that button, so this test cannot pass while the control has silently
     * stopped existing); the rest pin that clicking it genuinely reopens the
     * TPU/TPS step, keeping the chosen city and dropping the search that
     * belonged to the old cemetery.
     */
    public function test_the_completed_step_two_dot_reopens_the_cemetery_step(): void
    {
        $component = Livewire::test(RenewalStart::class, [
            'city' => LaunchCityCode::JAKARTA,
            'cemeteryId' => CemeteryFixture::id('package', 0),
            'name' => 'Contoh',
        ]);

        $component->assertSee('Langkah 3 dari 6')
            ->assertSee('wire:click="goToStep(2)"', false);

        $component->call('goToStep', RenewalJourneyStep::CEMETERY)
            ->assertOk()
            ->assertSet('cemeteryId', '')
            ->assertSet('city', LaunchCityCode::JAKARTA)
            ->assertSet('name', '')
            ->assertSet('searched', false)
            ->assertSee('Langkah 2 dari 6');
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
    // §6.5 — provider unavailable (city/cemetery read)
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

    // =====================================================================
    // Migrated from GraveSearchStatesTest — STATE 1: gate closed
    // (AC16, design-system.md §6.4)
    // =====================================================================

    /**
     * `2026_07_26_120400_seed_feature_gate_registry.php` seeds every gate
     * closed, so this is what an unmodified environment renders. Read from
     * the real seeded row rather than assumed.
     */
    public function test_the_data_gate_seeds_closed_so_gate_closed_is_the_default_state(): void
    {
        $gate = FeatureGate::query()->where('gate_id', 'G-DATA-01')->first();

        $this->assertNotNull($gate, 'G-DATA-01 must exist in the seeded feature_gates registry.');
        $this->assertSame('closed', $gate->state, 'This suite assumes the real seeded default (closed); update it if that default ever changes.');

        Livewire::test(RenewalStart::class, ['cemeteryId' => CemeteryFixture::id('package', 0)])
            ->assertOk()
            ->assertSee(self::GATE_CLOSED_MARKER);
    }

    /**
     * §6.4 and information-architecture.md §4: a closed gate produces an
     * explanatory page, never a generic 404.
     */
    public function test_the_gate_closed_state_explains_itself_and_offers_the_manual_path(): void
    {
        Livewire::test(RenewalStart::class, ['cemeteryId' => CemeteryFixture::id('package', 0)])
            ->assertOk()
            ->assertSee(self::GATE_CLOSED_MARKER)
            ->assertSee('Hubungi Bantuan')
            ->assertSee('/bantuan');
    }

    /**
     * The gate-closed page carries the same non-implication the
     * privacy-limited state needs, for the same reason: the capability
     * being switched off says nothing about whether the record exists.
     */
    public function test_the_gate_closed_state_never_implies_the_record_does_not_exist(): void
    {
        Livewire::test(RenewalStart::class, ['cemeteryId' => CemeteryFixture::id('package', 0)])
            ->assertSee('Ini tidak berarti data makam yang Anda cari tidak ada.')
            ->assertDontSee(self::NO_RESULT_MARKER)
            ->assertDontSee(self::PRIVACY_LIMITED_MARKER);
    }

    /**
     * UI/UX audit, 26 Aug 2026: the gate-closed page renders `icon="inbox"`
     * — the same complete, multi-segment glyph used for the no-result empty
     * state — never `icon.slash` (StatusIntent's bare-stroke badge glyph).
     */
    public function test_the_gate_closed_states_icon_is_the_complete_inbox_glyph_not_the_bare_slash(): void
    {
        $inboxSource = file_get_contents(resource_path('views/components/icon/inbox.blade.php'));
        preg_match('/<path[^>]*\bd="([^"]+)"/', $inboxSource, $inboxMatches);
        $this->assertNotEmpty($inboxMatches, 'icon.inbox must contain a <path d="..."> to compare against.');
        $inboxPathD = $inboxMatches[1];

        $slashSource = file_get_contents(resource_path('views/components/icon/slash.blade.php'));
        preg_match('/<path[^>]*\bd="([^"]+)"/', $slashSource, $slashMatches);
        $this->assertNotEmpty($slashMatches, 'icon.slash must contain a <path d="..."> to compare against.');
        $slashPathD = $slashMatches[1];

        Livewire::test(RenewalStart::class, ['cemeteryId' => CemeteryFixture::id('package', 0)])
            ->assertOk()
            ->assertSee(self::GATE_CLOSED_MARKER)
            ->assertSeeHtml($inboxPathD)
            ->assertDontSeeHtml($slashPathD);
    }

    /**
     * A closed gate must not be reachable around: even a request carrying a
     * name term renders the explanatory page, never a result set.
     */
    public function test_a_closed_gate_runs_no_search_even_when_the_request_carries_search_terms(): void
    {
        $seededName = (string) $this->firstPackageRecord()->deceased_name;

        Livewire::test(RenewalStart::class, [
            'cemeteryId' => CemeteryFixture::id('package', 0),
            'name' => $seededName,
        ])
            ->assertSee(self::GATE_CLOSED_MARKER)
            ->assertDontSee($seededName);
    }

    public function test_opening_the_gate_replaces_the_explanatory_page_with_the_search_form(): void
    {
        $this->openTheDataGate();

        Livewire::test(RenewalStart::class, ['cemeteryId' => CemeteryFixture::id('package', 0)])
            ->assertOk()
            ->assertDontSee(self::GATE_CLOSED_MARKER)
            ->assertSee('Cari Data Makam')
            ->assertSee('Nama almarhum');
    }

    // =====================================================================
    // Migrated from GraveSearchStatesTest — STATE 2: privacy-limited
    // (AC14, §6.2)
    // =====================================================================

    /**
     * The all-restricted example cemetery (`CemeteryExampleData::
     * ALL_RESTRICTED_SLUG`) is seeded with every record restricted, so this
     * search matches two records and can show neither.
     */
    public function test_a_fully_restricted_match_renders_the_privacy_limited_state(): void
    {
        $this->openTheDataGate();

        Livewire::test(RenewalStart::class, [
            'cemeteryId' => CemeteryFixture::id('all-restricted'),
            'name' => 'Contoh',
        ])
            ->assertOk()
            ->assertSee(self::PRIVACY_LIMITED_MARKER)
            ->assertSee('ada di sistem kami');
    }

    /**
     * Migrated from GraveSearchStatesTest — the privacy-limited state must
     * never say "not found": the record demonstrably exists.
     */
    public function test_the_privacy_limited_state_never_says_the_record_was_not_found(): void
    {
        $this->openTheDataGate();

        Livewire::test(RenewalStart::class, [
            'cemeteryId' => CemeteryFixture::id('all-restricted'),
            'name' => 'Contoh',
        ])
            ->assertSee(self::PRIVACY_LIMITED_MARKER)
            ->assertDontSee(self::NO_RESULT_MARKER)
            ->assertDontSee('tidak ditemukan')
            ->assertDontSee(self::GATE_CLOSED_MARKER);
    }

    /**
     * The state says a record exists; it must not then disclose the
     * identity the access mode withholds. Both of the all-restricted
     * cemetery's seeded records are restricted, so neither generated name
     * may appear. Resolved from the seed rather than named: the first
     * record (by block) is `limited`, whose location IS permitted; the
     * second is `closed`, and not even its block may be shown.
     */
    public function test_the_privacy_limited_state_discloses_no_withheld_name(): void
    {
        $this->openTheDataGate();

        [$limited, $closed] = $this->roleRecords('all-restricted');

        Livewire::test(RenewalStart::class, [
            'cemeteryId' => CemeteryFixture::id('all-restricted'),
            'name' => 'Contoh',
        ])
            ->assertDontSee((string) $limited->deceased_name)
            ->assertDontSee((string) $closed->deceased_name)
            // The `limited` row's location IS permitted.
            ->assertSee((string) $limited->block)
            // The `closed` row: not even its block may be shown.
            ->assertDontSee((string) $closed->block);
    }

    /**
     * A mixed search reports both facts. Showing one row and staying silent
     * about the second would be a quieter version of the same defect.
     *
     * The generator seeds no "mixed" cemetery (the package cemetery is all
     * OPEN, the all-restricted one is all restricted), so this test makes
     * the state locally: the package cemetery's second record is demoted
     * to `limited`, then the screen must show the readable row, report the
     * restricted match, and still withhold the demoted row's identity.
     */
    public function test_a_mixed_result_shows_readable_rows_and_still_reports_the_restricted_match(): void
    {
        $this->openTheDataGate();

        [$open, $restricted] = $this->makeThePackageCemeteryMixed();

        Livewire::test(RenewalStart::class, [
            'cemeteryId' => CemeteryFixture::id('package', 0),
            'name' => 'Contoh',
        ])
            ->assertSee((string) $open->deceased_name)
            ->assertSee(self::PRIVACY_LIMITED_MARKER)
            ->assertDontSee(self::NO_RESULT_MARKER)
            // The withheld row's name, still withheld even alongside
            // readable results.
            ->assertDontSee((string) $restricted->deceased_name);
    }

    // =====================================================================
    // Migrated from GraveSearchStatesTest — STATE 3: no result (AC5, §6.2)
    // =====================================================================

    /**
     * §6.2's three parts: what is empty, WHY (the registry may be
     * incomplete), and what to do next. AC5's "honest manual-entry or
     * customer-service path".
     */
    public function test_a_search_matching_nothing_renders_the_no_result_state_with_all_three_parts(): void
    {
        $this->openTheDataGate();

        Livewire::test(RenewalStart::class, [
            'cemeteryId' => CemeteryFixture::id('package', 0),
            'block' => 'ZZ-99',
        ])
            ->assertOk()
            // 1. What is empty.
            ->assertSee(self::NO_RESULT_MARKER)
            // 2. Why — and explicitly not "the grave does not exist".
            ->assertSee('Registri makam kami belum tentu lengkap')
            ->assertSee('belum tentu berarti makam yang Anda cari tidak ada')
            // 3. What to do next (AC5).
            ->assertSee('Input manual')
            ->assertSee('Hubungi bantuan');
    }

    /**
     * §6.2 applies to what is ANNOUNCED, not only to what is drawn.
     */
    public function test_the_no_result_announcement_carries_section_6_2s_three_parts_not_a_bare_count(): void
    {
        $this->openTheDataGate();

        Livewire::test(RenewalStart::class, [
            'cemeteryId' => CemeteryFixture::id('package', 0),
            'block' => 'ZZ-99',
        ])
            ->assertOk()
            // The bare count, gone.
            ->assertDontSee('0 data makam cocok dengan pencarian Anda')
            // 1. What is empty, and where — named from the generated
            //    cemetery, never a literal.
            ->assertSee('Data makam tidak ditemukan di '.CemeteryExampleData::bySlug(CemeteryExampleData::PACKAGE_CEMETERY_SLUGS[0])[1].'.')
            // 2. Why — and explicitly not "the grave does not exist".
            ->assertSee('Registri makam kami belum tentu lengkap, jadi hasil ini belum tentu berarti makam yang Anda cari tidak ada.')
            // 3. What to do next.
            ->assertSee('Lanjutkan lewat tombol Input manual atau Hubungi bantuan di bawah.');
    }

    /**
     * The count announcement is still correct where a count is the honest
     * thing to announce — the fix must not have flattened both branches
     * into the no-result wording.
     */
    public function test_a_matching_search_still_announces_its_count(): void
    {
        $this->openTheDataGate();

        $record = $this->firstPackageRecord();

        Livewire::test(RenewalStart::class, [
            'cemeteryId' => CemeteryFixture::id('package', 0),
            'block' => (string) $record->block,
        ])
            ->assertSee('1 data makam cocok dengan pencarian Anda.')
            ->assertDontSee('Registri makam kami belum tentu lengkap');
    }

    public function test_the_no_result_state_is_not_confused_with_the_other_two(): void
    {
        $this->openTheDataGate();

        Livewire::test(RenewalStart::class, [
            'cemeteryId' => CemeteryFixture::id('package', 0),
            'block' => 'ZZ-99',
        ])
            ->assertSee(self::NO_RESULT_MARKER)
            ->assertDontSee(self::PRIVACY_LIMITED_MARKER)
            ->assertDontSee(self::GATE_CLOSED_MARKER);
    }

    // =====================================================================
    // Migrated from GraveSearchStatesTest — §6.1 loading
    // =====================================================================

    /**
     * The skeleton and its `sr-only` announcement are in the server-rendered
     * HTML, keyed to the `search` action, with `aria-busy` on the container.
     * `assertSeeHtml`, not `assertSee`: `assertSee` escapes its needle, so
     * `aria-busy="true"` would be compared as `aria-busy=&quot;true&quot;`
     * and never match.
     */
    public function test_the_loading_skeleton_and_its_screen_reader_announcement_are_rendered(): void
    {
        $this->openTheDataGate();

        Livewire::test(RenewalStart::class, ['cemeteryId' => CemeteryFixture::id('package', 0)])
            ->assertOk()
            ->assertSee('Mencari data makam')
            ->assertSeeHtml('aria-busy="true"')
            ->assertSeeHtml('wire:loading.delay')
            ->assertSeeHtml('wire:target="search"');
    }

    // =====================================================================
    // Migrated from GraveSearchStatesTest — results, and the states that
    // must NOT appear
    // =====================================================================

    public function test_an_open_record_renders_its_full_row(): void
    {
        $this->openTheDataGate();

        $record = $this->firstPackageRecord();

        Livewire::test(RenewalStart::class, [
            'cemeteryId' => CemeteryFixture::id('package', 0),
            'block' => (string) $record->block,
        ])
            ->assertSee((string) $record->deceased_name)
            ->assertSee((string) $record->block)
            ->assertSee($record->death_date->format('Y-m-d'))
            ->assertSee($record->due_date->format('Y-m-d'))
            ->assertDontSee(self::NO_RESULT_MARKER)
            ->assertDontSee(self::PRIVACY_LIMITED_MARKER);
    }

    /**
     * Fabricated seed rows announce themselves on any surface that shows
     * them — the honesty marker carried through from the data layer to the
     * screen.
     */
    public function test_results_built_from_seeded_fixtures_are_labelled_as_example_data(): void
    {
        $this->openTheDataGate();

        Livewire::test(RenewalStart::class, [
            'cemeteryId' => CemeteryFixture::id('package', 0),
            'block' => (string) $this->firstPackageRecord()->block,
        ])
            ->assertSee('data contoh');
    }

    /**
     * The disclosure must not depend on there being a readable row to hang
     * it off. The all-restricted cemetery's two seeded records are both
     * restricted, so this search produces zero open results and renders
     * the privacy-limited card alone. The matches are still fabricated, so
     * the page must still say so.
     */
    public function test_a_restricted_only_result_set_still_discloses_that_its_matches_are_example_data(): void
    {
        $this->openTheDataGate();

        [$limited, $closed] = $this->roleRecords('all-restricted');

        Livewire::test(RenewalStart::class, [
            'cemeteryId' => CemeteryFixture::id('all-restricted'),
            'name' => 'Contoh',
        ])
            ->assertOk()
            ->assertSee(self::PRIVACY_LIMITED_MARKER)
            ->assertDontSee(self::NO_RESULT_MARKER)
            ->assertDontSee((string) $limited->deceased_name)
            ->assertDontSee((string) $closed->deceased_name)
            ->assertSee('data contoh');
    }

    /**
     * The worst possible version of the defect: telling a family their
     * relative was not found before they had typed anything. Arriving on
     * the screen (a cemetery selected, nothing searched) must render
     * neither empty state.
     */
    public function test_arriving_without_searching_renders_no_empty_state_at_all(): void
    {
        $this->openTheDataGate();

        Livewire::test(RenewalStart::class, ['cemeteryId' => CemeteryFixture::id('package', 0)])
            ->assertDontSee(self::NO_RESULT_MARKER)
            ->assertDontSee(self::PRIVACY_LIMITED_MARKER)
            ->assertSee('Isi minimal satu kolom');
    }

    /**
     * §6.3 — a blank submission is a validation error, never an empty
     * result.
     */
    public function test_a_blank_submission_is_a_validation_error_not_a_no_result(): void
    {
        $this->openTheDataGate();

        Livewire::test(RenewalStart::class, ['cemeteryId' => CemeteryFixture::id('package', 0)])
            ->call('search')
            ->assertHasErrors('name')
            ->assertDontSee(self::NO_RESULT_MARKER);
    }

    public function test_an_invalid_death_date_is_a_validation_error(): void
    {
        $this->openTheDataGate();

        Livewire::test(RenewalStart::class, ['cemeteryId' => CemeteryFixture::id('package', 0)])
            ->set('deathDate', '11-04-2018')
            ->call('search')
            ->assertHasErrors(['deathDate' => 'date_format'])
            ->assertDontSee(self::NO_RESULT_MARKER);
    }

    /**
     * `?tanggal=` is `#[Url]`-bound, so a value arriving as an initial
     * property (the same path a real `?tanggal=` GET takes) never passes
     * through `search()`. `mount()` validates it before render() decides
     * whether to search, so a malformed value renders §6.3's inline
     * per-field error, never an empty state.
     */
    public function test_a_malformed_death_date_renders_the_validation_state_not_an_empty_one(): void
    {
        $this->openTheDataGate();

        // '2018-13-45' is the interesting one: it is not a real calendar
        // date, but a lenient parser rolls it forward into one.
        foreach (['garbage', '11-04-2018', '2018-13-45', "' OR 1=1 --"] as $tampered) {
            Livewire::test(RenewalStart::class, [
                'cemeteryId' => CemeteryFixture::id('package', 0),
                'deathDate' => $tampered,
            ])
                ->assertOk()
                ->assertHasErrors('deathDate')
                ->assertSee('Tanggal wafat harus berupa tanggal yang valid.')
                ->assertDontSee(self::NO_RESULT_MARKER)
                ->assertDontSee(self::PRIVACY_LIMITED_MARKER)
                ->assertDontSee('Pencarian sedang tidak dapat diproses');
        }
    }

    /**
     * A well-formed `?tanggal=` still works — the guard above must reject
     * malformed dates without also breaking the shared/bookmarked result
     * link that `mount()` exists to support.
     */
    public function test_a_valid_death_date_still_runs_the_search(): void
    {
        $this->openTheDataGate();

        $record = $this->firstPackageRecord();

        Livewire::test(RenewalStart::class, [
            'cemeteryId' => CemeteryFixture::id('package', 0),
            'deathDate' => $record->death_date->format('Y-m-d'),
        ])
            ->assertOk()
            ->assertHasNoErrors()
            ->assertSee((string) $record->deceased_name);
    }

    // =====================================================================
    // Migrated from GraveSearchStatesTest — provider unavailable (§6.5),
    // the search read
    // =====================================================================

    /**
     * A backend failure produces an empty outcome. If the view branched on
     * emptiness before it branched on the failure, a downed database would
     * render as "your relative is not in the registry" — the defect caused
     * by an outage rather than by data.
     */
    public function test_a_search_backend_failure_is_never_reported_as_not_found(): void
    {
        $this->openTheDataGate();

        $cemeteryId = CemeteryFixture::id('package', 0);

        // Safe: RefreshDatabase rolls the whole test transaction back.
        // Children first, in reverse-dependency order — see this file's
        // §6.5 city/cemetery-read test above for the same tripwire
        // discipline applied to the fuller table list.
        DB::statement('DROP TABLE IF EXISTS pre_need_consultation_requests');
        DB::statement('DROP TABLE IF EXISTS pre_need_payment_schedules');
        DB::statement('DROP TABLE IF EXISTS pre_need_cases');
        DB::statement('DROP TABLE IF EXISTS abuse_reports');
        DB::statement('DROP TABLE IF EXISTS moderation_cases');
        DB::statement('DROP TABLE IF EXISTS memorial_qr_tokens');
        DB::statement('DROP TABLE IF EXISTS memorial_media');
        DB::statement('DROP TABLE IF EXISTS memorial_contents');
        DB::statement('DROP TABLE IF EXISTS memorial_editors');
        DB::statement('DROP TABLE IF EXISTS memorial_profiles');
        DB::statement('DROP TABLE IF EXISTS renewal_external_markings');
        DB::statement('DROP TABLE IF EXISTS renewal_quotes');
        DB::statement('DROP TABLE IF EXISTS renewals');
        DB::statement('DROP TABLE grave_records');

        Livewire::test(RenewalStart::class, ['cemeteryId' => $cemeteryId, 'name' => 'Contoh'])
            ->assertOk()
            ->assertSee('Pencarian sedang tidak dapat diproses')
            ->assertSee('Ini bukan hasil pencarian')
            ->assertDontSee(self::NO_RESULT_MARKER)
            ->assertDontSee(self::PRIVACY_LIMITED_MARKER);
    }

    // =====================================================================
    // Migrated from GraveSearchStatesTest — cemetery scoping
    // =====================================================================
    //
    // The former GraveSearch had its own "Pilih TPU/TPS terlebih dahulu."
    // redirect-style empty state for an orphaned `?tpu=` (a value that
    // resolves to no published cemetery), reached when the visitor landed
    // directly on the separate `/perpanjangan/cari` route with a bad id and
    // nothing else on the page to fall back to. That state no longer
    // exists as its own screen: normalizeCemetery() (unchanged from either
    // predecessor) resets `cemeteryId` to '' exactly as before, but now
    // Step 2 (TPU/TPS selection) is already visible on the SAME page rather
    // than absent, so there is nothing left needing a dedicated "go back"
    // message — the visitor simply sees Steps 1-2 with Step 3 not yet
    // revealed. What still MUST hold, and what these three tests assert
    // instead of the deleted copy string: the bad id is discarded (never
    // trusted into a query), no data leaks, and Step 3 does not render.
    // =====================================================================

    /**
     * `/perpanjangan` is a real route regardless of what `?tpu=` carries,
     * so an unusable id is not a 404 — it is silently discarded back to
     * step 2, same as a stale/unknown `?kota=`.
     */
    public function test_an_unknown_cemetery_is_discarded_and_step_three_never_renders(): void
    {
        $this->openTheDataGate();

        Livewire::test(RenewalStart::class, ['cemeteryId' => '00000000-0000-0000-0000-000000000000'])
            ->assertOk()
            ->assertSet('cemeteryId', '')
            // The stepper's own sr-only per-dot labels always mention every
            // step number ("Langkah 3: Cari Makam (belum tersedia)"), so
            // "Langkah 3" alone is not a safe absence marker — Step 3's own
            // section (heading, search form, gate-closed page) is.
            ->assertDontSee('Nama almarhum')
            ->assertDontSee(self::GATE_CLOSED_MARKER)
            ->assertDontSee(self::NO_RESULT_MARKER);
    }

    /**
     * A malformed `?tpu=` must not break the page. On PostgreSQL
     * `cemeteries.id` is a real `uuid` column, so querying it with a
     * non-UUID string is a database type error rather than a miss — see
     * `CemeteryPublicQuery::findPublishedById()`, which guards this with
     * `Str::isUuid()` before it ever reaches the database.
     */
    public function test_a_malformed_cemetery_identifier_does_not_break_the_page(): void
    {
        $this->openTheDataGate();

        $seededName = (string) $this->firstPackageRecord()->deceased_name;

        foreach (['garbage', "' OR 1=1 --", '../../etc/passwd'] as $tampered) {
            Livewire::test(RenewalStart::class, ['cemeteryId' => $tampered])
                ->assertOk()
                ->assertSet('cemeteryId', '')
                ->assertDontSee($seededName);
        }
    }

    /**
     * A draft cemetery must not become searchable by holding on to its id.
     * The deliberately-draft example cemetery (`CemeteryExampleData::
     * DRAFT_SLUG`) has one grave record parked in it precisely so this is
     * provable.
     */
    public function test_a_draft_cemetery_cannot_be_searched_through_a_held_id(): void
    {
        $this->openTheDataGate();

        $draftRecordName = (string) GraveRecord::query()
            ->where('cemetery_id', CemeteryFixture::id('draft'))
            ->firstOrFail()->deceased_name;

        Livewire::test(RenewalStart::class, [
            'cemeteryId' => CemeteryFixture::id('draft'),
            'name' => 'Contoh',
        ])
            ->assertSet('cemeteryId', '')
            ->assertDontSee($draftRecordName);
    }

    /**
     * AC1 — six visible steps, this journey's own labels, never the
     * booking wizard's nine, and the stepper genuinely reaches step 3 once
     * a city AND a published cemetery are both selected.
     */
    public function test_the_stepper_reaches_step_three_once_a_cemetery_is_selected(): void
    {
        $this->openTheDataGate();

        $component = Livewire::test(RenewalStart::class, [
            'city' => LaunchCityCode::JAKARTA,
            'cemeteryId' => CemeteryFixture::id('package', 0),
        ]);

        foreach (['Kota', 'TPU/TPS', 'Cari Makam', 'Biaya', 'Pembayaran', 'Konfirmasi'] as $label) {
            $component->assertSee($label);
        }

        $component->assertSee('Langkah 3 dari 6');

        foreach (['Jenis Layanan', 'Data Almarhum + Dokumen', 'Data Pemesan'] as $bookingOnlyLabel) {
            $component->assertDontSee($bookingOnlyLabel);
        }
    }

    /**
     * §6.10 — every transactional screen needs a support escape hatch, and
     * product-brief.md §5 point 10 requires it on every page, in every
     * grave-search empty state.
     */
    public function test_the_support_escape_hatch_is_present_in_every_grave_search_state(): void
    {
        $cemeteryId = CemeteryFixture::id('package', 0);

        // Gate closed.
        Livewire::test(RenewalStart::class, ['cemeteryId' => $cemeteryId])
            ->assertSee('/bantuan');

        $this->openTheDataGate();

        // No result.
        Livewire::test(RenewalStart::class, ['cemeteryId' => $cemeteryId, 'block' => 'ZZ-99'])
            ->assertSee('/bantuan');

        // Privacy limited.
        Livewire::test(RenewalStart::class, ['cemeteryId' => CemeteryFixture::id('all-restricted'), 'name' => 'Contoh'])
            ->assertSee('/bantuan');
    }

    // =====================================================================
    // Migrated from GraveSearchStatesTest — role-resolved fixtures
    // =====================================================================

    /**
     * The first seeded record (by block) of the package example cemetery —
     * OPEN by the generator's role rules. Resolved rather than named so a
     * generator change cannot orphan the assertion.
     */
    private function firstPackageRecord(): GraveRecord
    {
        return GraveRecord::query()
            ->where('cemetery_id', CemeteryFixture::id('package', 0))
            ->orderBy('block')
            ->firstOrFail();
    }

    /**
     * Both records of a role cemetery, ordered by block. For the
     * all-restricted cemetery: first `limited` (location disclosed), second
     * `closed` (nothing disclosed) — locked by
     * `GraveRecordSeedTest::test_seeded_record_counts_by_role_are_explicit`.
     * For the package cemetery the two records are both OPEN as seeded.
     *
     * @return list{GraveRecord, GraveRecord}
     */
    private function roleRecords(string $role, ?int $index = null): array
    {
        $records = GraveRecord::query()
            ->where('cemetery_id', CemeteryFixture::id($role, $index))
            ->orderBy('block')
            ->get();

        return [$records[0], $records[1]];
    }

    /**
     * The generator seeds no "mixed" cemetery, so this makes one locally:
     * the package cemetery's second record is demoted to `limited`. The
     * screen must then show the first (OPEN) record's row and still report
     * — and withhold — the second.
     *
     * @return list{GraveRecord, GraveRecord} the open row, then the demoted row
     */
    private function makeThePackageCemeteryMixed(): array
    {
        [$open, $restricted] = $this->roleRecords('package', 0);

        $restricted->update(['access_mode' => GraveRecordAccessMode::LIMITED]);

        return [$open, $restricted];
    }

    // =====================================================================
    // Migrated from GraveSearchPerformanceTest
    // =====================================================================

    /**
     * Request-level companion to `App\Console\Commands\
     * BenchGraveSearchCommand` (`docs/operations/performance-and-capacity.
     * md` §2 AC4: "below 500ms at 100,000 records"), which certifies
     * `GraveRegistryPublicQuery::search()`'s own wall-clock cost directly
     * (p95 7.19ms at 100k records) but not the full HTTP/Livewire request
     * cycle a real renewal user experiences — `RenewalStart::render()` is
     * what actually calls that query from a live request.
     *
     * SCALE NOTE (unchanged from the original suite's plan): this test
     * seeds ONE cemetery with 5,000 records (~5% of the certified 100k
     * target), not a full-scale dataset — the raw query cost at 100k is
     * already proven near-flat by BenchGraveSearchCommand, so what this
     * test adds is proof that Livewire/HTTP-layer overhead does not erase
     * that margin, which does not require 100k rows to demonstrate. This is
     * a request-level smoke/regression proof, not a full AC4
     * recertification.
     */
    public function test_a_full_search_request_completes_within_the_500ms_budget_at_a_representative_scale(): void
    {
        $this->openTheDataGate();

        Artisan::call(GenerateGraveRegistryLoadDatasetCommand::class, [
            '--cemeteries' => 1,
            '--records' => 5000,
        ]);

        $cemeteryId = DB::table('cemeteries')
            ->where('name', 'like', 'Contoh TPU Beban %')
            ->value('id');
        self::assertNotNull($cemeteryId, 'The benchmark dataset generator did not create a cemetery.');

        $sampleName = DB::table('grave_records')
            ->where('cemetery_id', $cemeteryId)
            ->value('deceased_name');
        self::assertNotNull($sampleName, 'The benchmark dataset generator did not create any grave records.');
        $searchTerm = mb_substr((string) $sampleName, 0, 4);

        $start = microtime(true);

        Livewire::test(RenewalStart::class, ['cemeteryId' => $cemeteryId])
            ->set('name', $searchTerm)
            ->call('search')
            ->assertSet('searched', true);

        $elapsedMs = (microtime(true) - $start) * 1000;

        self::assertLessThan(
            500.0,
            $elapsedMs,
            sprintf('Full request-level grave search took %.2fms, over the 500ms AC4 budget.', $elapsedMs)
        );
    }

    // =====================================================================
    // Screen 1 → Screen 2 handoff (Task 4 of the wizard-screen-
    // consolidation plan) — the index-based grave resolution wired to
    // GraveRegistryPublicQuery::resolveOpenRecordAt() and
    // RenewalGraveSelection.
    // =====================================================================

    public function test_selecting_a_search_result_stores_the_grave_id_in_session_never_in_the_url(): void
    {
        $this->openTheDataGate();
        $cemetery = Cemetery::query()->where('slug', CemeteryExampleData::PACKAGE_CEMETERY_SLUGS[0])->sole();
        $grave = GraveRecord::factory()->create([
            'cemetery_id' => $cemetery->id,
            'deceased_name' => 'Contoh Handoff Uji',
            'access_mode' => GraveRecordAccessMode::OPEN,
        ]);

        $component = Livewire::test(RenewalStart::class, [
            'cemeteryId' => $cemetery->id,
            'name' => 'Contoh Handoff Uji',
        ])->call('search');

        $html = $component->html();
        $this->assertStringNotContainsString($grave->id, $html);

        $component->call('selectGraveForRenewal', 0)
            ->assertRedirect(route('perpanjangan.pembayaran'));

        $this->assertSame($grave->id, RenewalGraveSelection::current());
    }

    public function test_an_index_that_no_longer_matches_shows_a_retry_error_instead_of_redirecting(): void
    {
        $this->openTheDataGate();
        $cemetery = Cemetery::query()->where('slug', CemeteryExampleData::PACKAGE_CEMETERY_SLUGS[0])->sole();

        Livewire::test(RenewalStart::class, [
            'cemeteryId' => $cemetery->id,
            'name' => 'Nama Yang Tidak Ada Sama Sekali',
        ])
            ->call('search')
            ->call('selectGraveForRenewal', 0)
            ->assertNoRedirect()
            ->assertSee('sudah tidak tersedia');
    }

    /**
     * `render()` has always treated its own call into the grave-matching
     * query as fallible (§6.5 "Provider unavailable"). `selectGraveForRenewal()`
     * calls the SAME matching logic through `resolveOpenRecordAt()`, so a
     * registry read that fails between the results rendering and the visitor
     * clicking one must degrade the same way rather than 500.
     *
     * Dropping `grave_records` is what makes that read fail — and it is also
     * what makes this a COMPOSITION test rather than a single-guard one: on
     * PostgreSQL the failed statement aborts the whole ambient transaction
     * (SQLSTATE 25P02), so every later read in the same request fails too,
     * including `normalizeCemetery()`'s and `render()`'s own
     * `CemeteryPublicQuery::findPublishedById()` calls against the
     * perfectly-healthy `cemeteries` table. All three guards are load-bearing
     * here; remove any one and this test fails with an uncaught exception.
     * The visitor is left on an honest "sedang tidak dapat dimuat" screen
     * with the support escape hatch, never redirected onward, and nothing is
     * remembered as a selection.
     */
    public function test_a_failed_registry_read_when_selecting_a_result_degrades_honestly_instead_of_500ing(): void
    {
        $this->openTheDataGate();

        $component = Livewire::test(RenewalStart::class, [
            'cemeteryId' => CemeteryFixture::id('package', 0),
            'name' => 'Contoh',
        ])->call('search')->assertSet('searched', true);

        // Reverse-dependency order: PostgreSQL blocks `DROP TABLE` of a
        // parent by ANY incoming FK (2BP01). `memorial_profiles` and
        // `renewals` both FK-reference `grave_records`, and each has its own
        // dependents in turn — the same drop-list discipline (and reasoning)
        // as `test_a_failed_cemetery_read_degrades_honestly_instead_of_500ing`
        // above, trimmed to `grave_records`' own referencers.
        Schema::dropIfExists('abuse_reports');
        Schema::dropIfExists('moderation_cases');
        Schema::dropIfExists('memorial_qr_tokens');
        Schema::dropIfExists('memorial_media');
        Schema::dropIfExists('memorial_contents');
        Schema::dropIfExists('memorial_editors');
        Schema::dropIfExists('memorial_profiles');
        Schema::dropIfExists('renewal_external_markings');
        Schema::dropIfExists('renewal_quotes');
        Schema::dropIfExists('renewals');
        Schema::dropIfExists('grave_records');

        $component->call('selectGraveForRenewal', 0)
            ->assertOk()
            ->assertNoRedirect()
            ->assertSee('/bantuan');

        $this->assertNull(RenewalGraveSelection::current());
    }

    /**
     * The gate that already refuses the search form must also refuse the
     * forward action directly — a race where the gate closes between the
     * search render and the click must not still redirect.
     */
    public function test_selecting_a_result_while_the_gate_is_closed_does_not_redirect(): void
    {
        $cemetery = Cemetery::query()->where('slug', CemeteryExampleData::PACKAGE_CEMETERY_SLUGS[0])->sole();

        Livewire::test(RenewalStart::class, ['cemeteryId' => $cemetery->id])
            ->call('selectGraveForRenewal', 0)
            ->assertNoRedirect();

        $this->assertNull(RenewalGraveSelection::current());
    }
}
