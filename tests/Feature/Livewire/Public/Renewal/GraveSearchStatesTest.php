<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Public\Renewal;

use App\Domain\GraveRegistry\GraveRecordAccessMode;
use App\Domain\GraveRegistry\Models\GraveRecord;
use App\Livewire\Public\Renewal\GraveSearch;
use App\Platform\FeatureGate\Models\FeatureGate;
use App\Support\ExampleData\CemeteryExampleData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\CemeteryFixture;
use Tests\TestCase;

/**
 * `App\Livewire\Public\Renewal\GraveSearch` — screen PUB-031, Sprint 4
 * S4-T7. `.kiro/specs/renewal-and-grave-registry` AC3, AC5, AC14, AC16.
 *
 * ===========================================================================
 * THIS FILE'S ONE JOB: prove the three empty states are three
 * ===========================================================================
 * `tasks.md` names collapsing them into one message as a defect, because
 * "a family searching for a grave record and finding nothing must not
 * conclude the grave does not exist." Several tests below therefore assert
 * what is ABSENT as hard as what is present — a privacy-limited screen that
 * also said "tidak ditemukan" would pass a present-only assertion while
 * being exactly the defect.
 *
 * ---------------------------------------------------------------------------
 * `Livewire::test()`, not `$this->get('/perpanjangan/cari')`
 * ---------------------------------------------------------------------------
 * The route does not exist yet. `routes/web.php` is a shared file this
 * batch does not own, so the exact `Route::get(...)` lines are reported to
 * the batch lead rather than edited in, and these tests exercise the
 * component directly in the meantime. Consequence, stated rather than
 * glossed: **the route itself, its name, and its HTTP status are NOT TESTED
 * here.** A real-HTTP route test belongs with whoever wires the route, and
 * `withoutVite()` will be required in it (this file needs none —
 * `Livewire::test()` renders the component view without the `@vite(...)`
 * layout).
 *
 * `Livewire::withQueryParams()` is how the `#[Url]`-bound properties are
 * populated, which is the same path a real request uses — verified against
 * the sibling project's installed `LivewireManager::withQueryParams()` on
 * Livewire 4, not assumed from Livewire 3 habits.
 */
final class GraveSearchStatesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Copy fragments that identify each of the three states. Asserting one
     * state's fragment ABSENT while another is present is what proves they
     * were not collapsed.
     */
    private const GATE_CLOSED_MARKER = 'Pencarian Data Makam Belum Tersedia';

    private const NO_RESULT_MARKER = 'Data makam tidak ditemukan.';

    private const PRIVACY_LIMITED_MARKER = 'aksesnya dibatasi';

    private function openTheDataGate(): void
    {
        FeatureGate::query()->where('gate_id', 'G-DATA-01')->update(['state' => 'open']);
    }

    // =====================================================================
    // STATE 1 — gate closed (AC16, design-system.md §6.4)
    // =====================================================================

    /**
     * `2026_07_26_120400_seed_feature_gate_registry.php` seeds every gate
     * closed, so this is what an unmodified environment renders. Read from
     * the real seeded row rather than assumed — the same discipline
     * `HomePageRouteTest` applies to `G-OPS-01`.
     */
    public function test_the_data_gate_seeds_closed_so_gate_closed_is_the_default_state(): void
    {
        $gate = FeatureGate::query()->where('gate_id', 'G-DATA-01')->first();

        $this->assertNotNull($gate, 'G-DATA-01 must exist in the seeded feature_gates registry.');
        $this->assertSame('closed', $gate->state, 'This suite assumes the real seeded default (closed); update it if that default ever changes.');

        Livewire::withQueryParams(['tpu' => CemeteryFixture::id('package', 0)])
            ->test(GraveSearch::class)
            ->assertOk()
            ->assertSee(self::GATE_CLOSED_MARKER);
    }

    /**
     * §6.4 and information-architecture.md §4: a closed gate produces an
     * explanatory page, never a generic 404. Livewire's own `assertOk()` is
     * the component-level equivalent of that status assertion; the route
     * status itself is NOT TESTED here (see the class doc block).
     */
    public function test_the_gate_closed_state_explains_itself_and_offers_the_manual_path(): void
    {
        Livewire::withQueryParams(['tpu' => CemeteryFixture::id('package', 0)])
            ->test(GraveSearch::class)
            ->assertOk()
            ->assertSee(self::GATE_CLOSED_MARKER)
            // The documented fallback path (§6.4's "Gate closed" row).
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
        Livewire::withQueryParams(['tpu' => CemeteryFixture::id('package', 0)])
            ->test(GraveSearch::class)
            ->assertSee('Ini tidak berarti data makam yang Anda cari tidak ada.')
            ->assertDontSee(self::NO_RESULT_MARKER)
            ->assertDontSee(self::PRIVACY_LIMITED_MARKER);
    }

    /**
     * A closed gate must not be reachable around: even a URL carrying a
     * name term renders the explanatory page, never a result set.
     */
    public function test_a_closed_gate_runs_no_search_even_when_the_url_carries_search_terms(): void
    {
        $seededName = (string) $this->firstPackageRecord()->deceased_name;

        Livewire::withQueryParams([
            'tpu' => CemeteryFixture::id('package', 0),
            'nama' => $seededName,
        ])
            ->test(GraveSearch::class)
            ->assertSee(self::GATE_CLOSED_MARKER)
            ->assertDontSee($seededName);
    }

    public function test_opening_the_gate_replaces_the_explanatory_page_with_the_search_form(): void
    {
        $this->openTheDataGate();

        Livewire::withQueryParams(['tpu' => CemeteryFixture::id('package', 0)])
            ->test(GraveSearch::class)
            ->assertOk()
            ->assertDontSee(self::GATE_CLOSED_MARKER)
            ->assertSee('Cari Data Makam')
            ->assertSee('Nama almarhum');
    }

    // =====================================================================
    // STATE 2 — privacy-limited (AC14, §6.2)
    // =====================================================================

    /**
     * The all-restricted example cemetery (`CemeteryExampleData::
     * ALL_RESTRICTED_SLUG`) is seeded with every record restricted, so this
     * search matches two records and can show neither.
     */
    public function test_a_fully_restricted_match_renders_the_privacy_limited_state(): void
    {
        $this->openTheDataGate();

        Livewire::withQueryParams([
            'tpu' => CemeteryFixture::id('all-restricted'),
            'nama' => 'Contoh',
        ])
            ->test(GraveSearch::class)
            ->assertOk()
            ->assertSee(self::PRIVACY_LIMITED_MARKER)
            ->assertSee('ada di sistem kami');
    }

    /**
     * THE assertion this whole screen exists for. A restricted match must
     * never be worded as "not found" — the record demonstrably exists, and
     * telling a family otherwise is the documented defect.
     */
    public function test_the_privacy_limited_state_never_says_the_record_was_not_found(): void
    {
        $this->openTheDataGate();

        Livewire::withQueryParams([
            'tpu' => CemeteryFixture::id('all-restricted'),
            'nama' => 'Contoh',
        ])
            ->test(GraveSearch::class)
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

        Livewire::withQueryParams([
            'tpu' => CemeteryFixture::id('all-restricted'),
            'nama' => 'Contoh',
        ])
            ->test(GraveSearch::class)
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

        Livewire::withQueryParams([
            'tpu' => CemeteryFixture::id('package', 0),
            'nama' => 'Contoh',
        ])
            ->test(GraveSearch::class)
            ->assertSee((string) $open->deceased_name)
            ->assertSee(self::PRIVACY_LIMITED_MARKER)
            ->assertDontSee(self::NO_RESULT_MARKER)
            // The withheld row's name, still withheld even alongside
            // readable results.
            ->assertDontSee((string) $restricted->deceased_name);
    }

    // =====================================================================
    // STATE 3 — no result (AC5, §6.2)
    // =====================================================================

    /**
     * §6.2's three parts: what is empty, WHY (the registry may be
     * incomplete), and what to do next. AC5's "honest manual-entry or
     * customer-service path".
     */
    public function test_a_search_matching_nothing_renders_the_no_result_state_with_all_three_parts(): void
    {
        $this->openTheDataGate();

        Livewire::withQueryParams([
            'tpu' => CemeteryFixture::id('package', 0),
            'blok' => 'ZZ-99',
        ])
            ->test(GraveSearch::class)
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
     * §6.2 applies to what is ANNOUNCED, not only to what is drawn. The
     * screen's one `aria-live` region used to read "{count} data makam cocok
     * dengan pencarian Anda" for every outcome, so a screen-reader user
     * whose search matched nothing heard "0 data makam cocok dengan
     * pencarian Anda" — a bare no-data announcement, forbidden in terms by
     * design-system.md §6.2 ("Never a bare 'Tidak ada data'").
     *
     * Scope, stated rather than implied: this covers the announcement's
     * COPY, which is server-rendered and therefore assertable here. Whether
     * a live region present in the DOM from first paint is actually
     * announced on a URL-arrival page load is a DOM-timing question no test
     * in this repository can answer — there is no browser, Dusk, Playwright
     * or Cypress harness. That half is ledgered under the program-level
     * browser-harness gap, NOT TESTED.
     */
    public function test_the_no_result_announcement_carries_section_6_2s_three_parts_not_a_bare_count(): void
    {
        $this->openTheDataGate();

        Livewire::withQueryParams([
            'tpu' => CemeteryFixture::id('package', 0),
            'blok' => 'ZZ-99',
        ])
            ->test(GraveSearch::class)
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

        Livewire::withQueryParams([
            'tpu' => CemeteryFixture::id('package', 0),
            'blok' => (string) $record->block,
        ])
            ->test(GraveSearch::class)
            ->assertSee('1 data makam cocok dengan pencarian Anda.')
            ->assertDontSee('Registri makam kami belum tentu lengkap');
    }

    public function test_the_no_result_state_is_not_confused_with_the_other_two(): void
    {
        $this->openTheDataGate();

        Livewire::withQueryParams([
            'tpu' => CemeteryFixture::id('package', 0),
            'blok' => 'ZZ-99',
        ])
            ->test(GraveSearch::class)
            ->assertSee(self::NO_RESULT_MARKER)
            ->assertDontSee(self::PRIVACY_LIMITED_MARKER)
            ->assertDontSee(self::GATE_CLOSED_MARKER);
    }

    // =====================================================================
    // §6.1 loading
    // =====================================================================

    /**
     * `tasks.md` lists the loading state FIRST among the states
     * "Implemented and CI-green", and this file had no assertion about it
     * anywhere — `AGENTS.md` §Testing: "Every traceability item marked
     * `Covered` needs test evidence." The markup was genuinely present and
     * genuinely server-rendered all along, so this closes a missing
     * assertion, not a missing feature.
     *
     * What CAN be asserted here: the skeleton and its `sr-only`
     * announcement are in the server-rendered HTML, keyed to the `search`
     * action, and the container carries `aria-busy`. `wire:loading.delay` is
     * asserted too, because that attribute is the whole mechanism — without
     * it the skeleton would be permanently visible rather than shown during
     * a request.
     *
     * What CANNOT be asserted here, stated rather than glossed: that the
     * skeleton actually APPEARS during an in-flight request, and that its
     * reserved heights hold CLS under 0.1. Both need a browser, and no
     * browser/Dusk/Playwright/Cypress harness exists in this repository —
     * NOT TESTED, ledgered under the program-level accessibility gap.
     *
     * `assertSeeHtml`, not `assertSee`: `assertSee` escapes its needle, so
     * `aria-busy="true"` would be compared as `aria-busy=&quot;true&quot;`
     * and never match.
     */
    public function test_the_loading_skeleton_and_its_screen_reader_announcement_are_rendered(): void
    {
        $this->openTheDataGate();

        Livewire::withQueryParams(['tpu' => CemeteryFixture::id('package', 0)])
            ->test(GraveSearch::class)
            ->assertOk()
            // The announcement a screen-reader user gets — §6.1: "Every
            // skeleton carries an sr-only announcement; a screen-reader user
            // hears nothing from a pulsing box."
            ->assertSee('Mencari data makam')
            ->assertSeeHtml('aria-busy="true"')
            // Shown only while the `search` action is in flight, and only
            // after the delay — not a permanently-visible box.
            ->assertSeeHtml('wire:loading.delay')
            ->assertSeeHtml('wire:target="search"');
    }

    // =====================================================================
    // Results, and the states that must NOT appear
    // =====================================================================

    public function test_an_open_record_renders_its_full_row(): void
    {
        $this->openTheDataGate();

        $record = $this->firstPackageRecord();

        Livewire::withQueryParams([
            'tpu' => CemeteryFixture::id('package', 0),
            'blok' => (string) $record->block,
        ])
            ->test(GraveSearch::class)
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

        Livewire::withQueryParams([
            'tpu' => CemeteryFixture::id('package', 0),
            'blok' => (string) $this->firstPackageRecord()->block,
        ])
            ->test(GraveSearch::class)
            ->assertSee('data contoh');
    }

    /**
     * The disclosure must not depend on there being a readable row to hang
     * it off. The all-restricted cemetery's two seeded records are both
     * restricted — first `limited`, second `closed` — so this search
     * produces zero open results and renders the privacy-limited card
     * alone. The matches are still fabricated, so the page must still say
     * so.
     *
     * Before this was fixed the label was computed from `openResults` alone
     * and rendered only inside the open-results branch, so this exact
     * search showed fictional data with no disclosure whatsoever — on any
     * seeded environment.
     */
    public function test_a_restricted_only_result_set_still_discloses_that_its_matches_are_example_data(): void
    {
        $this->openTheDataGate();

        [$limited, $closed] = $this->roleRecords('all-restricted');

        Livewire::withQueryParams([
            'tpu' => CemeteryFixture::id('all-restricted'),
            'nama' => 'Contoh',
        ])
            ->test(GraveSearch::class)
            ->assertOk()
            // There are matches, and they are all restricted.
            ->assertSee(self::PRIVACY_LIMITED_MARKER)
            ->assertDontSee(self::NO_RESULT_MARKER)
            // Neither withheld name appears, so no open row is being
            // rendered that the label could be hanging off.
            ->assertDontSee((string) $limited->deceased_name)
            ->assertDontSee((string) $closed->deceased_name)
            ->assertSee('data contoh');
    }

    /**
     * The worst possible version of the defect: telling a family their
     * relative was not found before they had typed anything. Arriving on
     * the screen must render neither empty state.
     */
    public function test_arriving_without_searching_renders_no_empty_state_at_all(): void
    {
        $this->openTheDataGate();

        Livewire::withQueryParams(['tpu' => CemeteryFixture::id('package', 0)])
            ->test(GraveSearch::class)
            ->assertDontSee(self::NO_RESULT_MARKER)
            ->assertDontSee(self::PRIVACY_LIMITED_MARKER)
            ->assertSee('Isi minimal satu kolom');
    }

    /**
     * §6.3 — a blank submission is a validation error, never an empty
     * result. Rendering "tidak ditemukan" for a form the visitor never
     * filled in would be the same defect wearing a different hat.
     */
    public function test_a_blank_submission_is_a_validation_error_not_a_no_result(): void
    {
        $this->openTheDataGate();

        Livewire::withQueryParams(['tpu' => CemeteryFixture::id('package', 0)])
            ->test(GraveSearch::class)
            ->call('search')
            ->assertHasErrors('name')
            ->assertDontSee(self::NO_RESULT_MARKER);
    }

    public function test_an_invalid_death_date_is_a_validation_error(): void
    {
        $this->openTheDataGate();

        Livewire::withQueryParams(['tpu' => CemeteryFixture::id('package', 0)])
            ->test(GraveSearch::class)
            ->set('deathDate', '11-04-2018')
            ->call('search')
            ->assertHasErrors(['deathDate' => 'date_format'])
            ->assertDontSee(self::NO_RESULT_MARKER);
    }

    /**
     * The same test the `?tpu=` tamper case already had, for the parameter
     * that never got one — which is exactly why the production gap survived.
     *
     * `?tanggal=` is `#[Url]`-bound, so it arrives on a plain GET and never
     * passes through `search()`. Before `mount()` validated, a malformed
     * value went straight into `whereDate('death_date', …)`: on PostgreSQL
     * that throws and the page renders §6.5 "provider unavailable", on SQLite
     * it renders the no-result state. Both are wrong, and the SQLite one is
     * the documented defect — a family told nothing matched when the real
     * problem is a date they can fix.
     *
     * §6.3 is the correct state: an inline per-field error on the form, with
     * no search run. Asserted as three separate facts (error present,
     * message rendered, neither empty state rendered) because a page that
     * merely avoids 500ing is not the same as a page that says what is
     * wrong.
     */
    public function test_a_malformed_death_date_in_the_url_renders_the_validation_state_not_an_empty_one(): void
    {
        $this->openTheDataGate();

        // '2018-13-45' is the interesting one: it is not a real calendar
        // date, but a lenient parser rolls it forward into one.
        foreach (['garbage', '11-04-2018', '2018-13-45', "' OR 1=1 --"] as $tampered) {
            Livewire::withQueryParams([
                'tpu' => CemeteryFixture::id('package', 0),
                'tanggal' => $tampered,
            ])
                ->test(GraveSearch::class)
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
    public function test_a_valid_death_date_in_the_url_still_runs_the_search(): void
    {
        $this->openTheDataGate();

        $record = $this->firstPackageRecord();

        Livewire::withQueryParams([
            'tpu' => CemeteryFixture::id('package', 0),
            'tanggal' => $record->death_date->format('Y-m-d'),
        ])
            ->test(GraveSearch::class)
            ->assertOk()
            ->assertHasNoErrors()
            ->assertSee((string) $record->deceased_name);
    }

    // =====================================================================
    // Provider unavailable (§6.5) — the fourth thing that is not an empty
    // state, and the easiest one to get wrong
    // =====================================================================

    /**
     * A backend failure produces an empty outcome. If the view branched on
     * emptiness before it branched on the failure, a downed database would
     * render as "your relative is not in the registry" — the defect caused
     * by an outage rather than by data.
     *
     * Forced with a real dropped table inside the test transaction, the
     * same technique `HomePageRouteTest` uses for the FAQ panel, rather
     * than by mocking the query class.
     */
    public function test_a_search_backend_failure_is_never_reported_as_not_found(): void
    {
        $this->openTheDataGate();

        $cemeteryId = CemeteryFixture::id('package', 0);

        // Safe: RefreshDatabase rolls the whole test transaction back.
        // Children first, in reverse-dependency order: the renewal tables FK
        // into `grave_records`, so they must be dropped before it or
        // PostgreSQL refuses with 2BP01. P4 memorial tables (16 Aug 2026)
        // come before the renewal tables: every memorial table FK-references
        // `memorial_profiles`, which FK-references `grave_records`
        // (restrictOnDelete), so all seven must precede `grave_records`
        // below (2BP01). P5a pre-need tables (16 Aug 2026) come first:
        // `pre_need_payment_schedules` FK-references `pre_need_cases`
        // (restrictOnDelete), and `pre_need_cases` FK-references
        // `quotes`/`plot_reservations`/`funeral_cases`, so both must be
        // dropped before any plot/quote/order table (2BP01).
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

        Livewire::withQueryParams(['tpu' => $cemeteryId, 'nama' => 'Contoh'])
            ->test(GraveSearch::class)
            ->assertOk()
            ->assertSee('Pencarian sedang tidak dapat diproses')
            ->assertSee('Ini bukan hasil pencarian')
            ->assertDontSee(self::NO_RESULT_MARKER)
            ->assertDontSee(self::PRIVACY_LIMITED_MARKER);
    }

    // =====================================================================
    // Cemetery scoping and the stepper contract
    // =====================================================================

    /**
     * `/perpanjangan/cari` is a real route, so an unusable `?tpu=` is not a
     * 404 — it is a visitor who needs sending back to step 2.
     */
    public function test_an_unknown_cemetery_sends_the_visitor_back_to_step_2_rather_than_404ing(): void
    {
        $this->openTheDataGate();

        Livewire::withQueryParams(['tpu' => '00000000-0000-0000-0000-000000000000'])
            ->test(GraveSearch::class)
            ->assertOk()
            ->assertSee('Pilih TPU/TPS terlebih dahulu.')
            ->assertDontSee(self::NO_RESULT_MARKER);
    }

    /**
     * A malformed `?tpu=` must render the "choose a TPU/TPS" state, not a
     * 500. On PostgreSQL `cemeteries.id` is a real `uuid` column, so
     * querying it with a non-UUID string is a database type error rather
     * than a miss — see `CemeteryPublicQuery::findPublishedById()`.
     */
    public function test_a_malformed_cemetery_identifier_does_not_break_the_page(): void
    {
        $this->openTheDataGate();

        $seededName = (string) $this->firstPackageRecord()->deceased_name;

        foreach (['garbage', "' OR 1=1 --", '../../etc/passwd'] as $tampered) {
            Livewire::withQueryParams(['tpu' => $tampered, 'nama' => 'Contoh'])
                ->test(GraveSearch::class)
                ->assertOk()
                ->assertSee('Pilih TPU/TPS terlebih dahulu.')
                ->assertDontSee($seededName);
        }
    }

    /**
     * A draft cemetery must not become searchable by holding on to its id
     * in a URL. The deliberately-draft example cemetery
     * (`CemeteryExampleData::DRAFT_SLUG`) has one grave record parked in it
     * precisely so this is provable.
     */
    public function test_a_draft_cemetery_cannot_be_searched_through_a_held_url(): void
    {
        $this->openTheDataGate();

        $draftRecordName = (string) GraveRecord::query()
            ->where('cemetery_id', CemeteryFixture::id('draft'))
            ->firstOrFail()->deceased_name;

        Livewire::withQueryParams([
            'tpu' => CemeteryFixture::id('draft'),
            'nama' => 'Contoh',
        ])
            ->test(GraveSearch::class)
            ->assertSee('Pilih TPU/TPS terlebih dahulu.')
            ->assertDontSee($draftRecordName);
    }

    /**
     * AC1 — six visible steps, this journey's own labels, never the
     * booking wizard's nine. `<x-mk.stepper>` defaults to the nine booking
     * labels when `labels` is omitted, so a regression here renders a
     * booking progress bar on a renewal screen.
     */
    public function test_the_stepper_shows_this_journeys_six_steps_and_not_the_nine_booking_ones(): void
    {
        $this->openTheDataGate();

        $component = Livewire::withQueryParams(['tpu' => CemeteryFixture::id('package', 0)])
            ->test(GraveSearch::class);

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
     * product-brief.md §5 point 10 requires it on every page.
     */
    public function test_the_support_escape_hatch_is_present_in_every_state(): void
    {
        $cemeteryId = CemeteryFixture::id('package', 0);

        // Gate closed.
        Livewire::withQueryParams(['tpu' => $cemeteryId])
            ->test(GraveSearch::class)
            ->assertSee('/bantuan');

        $this->openTheDataGate();

        // No result.
        Livewire::withQueryParams(['tpu' => $cemeteryId, 'blok' => 'ZZ-99'])
            ->test(GraveSearch::class)
            ->assertSee('/bantuan');

        // Privacy limited.
        Livewire::withQueryParams(['tpu' => CemeteryFixture::id('all-restricted'), 'nama' => 'Contoh'])
            ->test(GraveSearch::class)
            ->assertSee('/bantuan');
    }

    // =====================================================================
    // Role-resolved fixtures
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
}
