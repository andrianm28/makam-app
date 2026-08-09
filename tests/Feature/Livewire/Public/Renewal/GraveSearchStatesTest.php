<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Public\Renewal;

use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Livewire\Public\Renewal\GraveSearch;
use App\Platform\FeatureGate\Models\FeatureGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
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

    private function cemeteryId(string $slug): string
    {
        return (string) Cemetery::query()->where('slug', $slug)->sole()->id;
    }

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

        Livewire::withQueryParams(['tpu' => $this->cemeteryId('tpu-jakarta-menteng')])
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
        Livewire::withQueryParams(['tpu' => $this->cemeteryId('tpu-jakarta-menteng')])
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
        Livewire::withQueryParams(['tpu' => $this->cemeteryId('tpu-jakarta-menteng')])
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
        Livewire::withQueryParams([
            'tpu' => $this->cemeteryId('tpu-jakarta-menteng'),
            'nama' => 'Contoh Budi Santoso',
        ])
            ->test(GraveSearch::class)
            ->assertSee(self::GATE_CLOSED_MARKER)
            ->assertDontSee('Contoh Budi Santoso');
    }

    public function test_opening_the_gate_replaces_the_explanatory_page_with_the_search_form(): void
    {
        $this->openTheDataGate();

        Livewire::withQueryParams(['tpu' => $this->cemeteryId('tpu-jakarta-menteng')])
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
     * `TPS Jakarta Kemang` is seeded with every record restricted, so this
     * search matches two records and can show neither.
     */
    public function test_a_fully_restricted_match_renders_the_privacy_limited_state(): void
    {
        $this->openTheDataGate();

        Livewire::withQueryParams([
            'tpu' => $this->cemeteryId('tps-jakarta-kemang'),
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
            'tpu' => $this->cemeteryId('tps-jakarta-kemang'),
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
     * identity the access mode withholds. Both seeded Kemang records are
     * restricted, so neither name may appear.
     */
    public function test_the_privacy_limited_state_discloses_no_withheld_name(): void
    {
        $this->openTheDataGate();

        Livewire::withQueryParams([
            'tpu' => $this->cemeteryId('tps-jakarta-kemang'),
            'nama' => 'Contoh',
        ])
            ->test(GraveSearch::class)
            ->assertDontSee('Contoh Agus Priyono')
            ->assertDontSee('Contoh Dewi Anggraini')
            // C-01 is the `limited` row, whose location IS permitted.
            ->assertSee('C-01')
            // C-04 is the `closed` row: not even its block may be shown.
            ->assertDontSee('C-04');
    }

    /**
     * A mixed search reports both facts. Showing three rows and staying
     * silent about the fourth would be a quieter version of the same
     * defect.
     */
    public function test_a_mixed_result_shows_readable_rows_and_still_reports_the_restricted_match(): void
    {
        $this->openTheDataGate();

        Livewire::withQueryParams([
            'tpu' => $this->cemeteryId('tpu-jakarta-menteng'),
            'nama' => 'Contoh',
        ])
            ->test(GraveSearch::class)
            ->assertSee('Contoh Budi Santoso')
            ->assertSee(self::PRIVACY_LIMITED_MARKER)
            ->assertDontSee(self::NO_RESULT_MARKER)
            // The withheld row's name, still withheld even alongside
            // readable results.
            ->assertDontSee('Contoh Sri Handayani');
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
            'tpu' => $this->cemeteryId('tpu-jakarta-menteng'),
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

    public function test_the_no_result_state_is_not_confused_with_the_other_two(): void
    {
        $this->openTheDataGate();

        Livewire::withQueryParams([
            'tpu' => $this->cemeteryId('tpu-jakarta-menteng'),
            'blok' => 'ZZ-99',
        ])
            ->test(GraveSearch::class)
            ->assertSee(self::NO_RESULT_MARKER)
            ->assertDontSee(self::PRIVACY_LIMITED_MARKER)
            ->assertDontSee(self::GATE_CLOSED_MARKER);
    }

    // =====================================================================
    // Results, and the states that must NOT appear
    // =====================================================================

    public function test_an_open_record_renders_its_full_row(): void
    {
        $this->openTheDataGate();

        Livewire::withQueryParams([
            'tpu' => $this->cemeteryId('tpu-jakarta-menteng'),
            'blok' => 'A-12',
        ])
            ->test(GraveSearch::class)
            ->assertSee('Contoh Budi Santoso')
            ->assertSee('A-12')
            ->assertSee('2018-04-11')
            ->assertSee('2026-04-11')
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
            'tpu' => $this->cemeteryId('tpu-jakarta-menteng'),
            'blok' => 'A-12',
        ])
            ->test(GraveSearch::class)
            ->assertSee('data contoh');
    }

    /**
     * The disclosure must not depend on there being a readable row to hang
     * it off. `TPS Jakarta Kemang`'s only two seeded records are both
     * restricted — `C-01` limited, `C-04` closed — so this search produces
     * zero open results and renders the privacy-limited card alone. The
     * matches are still fabricated, so the page must still say so.
     *
     * Before this was fixed the label was computed from `openResults` alone
     * and rendered only inside the open-results branch, so this exact
     * search showed fictional data with no disclosure whatsoever — on any
     * seeded environment.
     */
    public function test_a_restricted_only_result_set_still_discloses_that_its_matches_are_example_data(): void
    {
        $this->openTheDataGate();

        Livewire::withQueryParams([
            'tpu' => $this->cemeteryId('tps-jakarta-kemang'),
            'nama' => 'Contoh',
        ])
            ->test(GraveSearch::class)
            ->assertOk()
            // There are matches, and they are all restricted.
            ->assertSee(self::PRIVACY_LIMITED_MARKER)
            ->assertDontSee(self::NO_RESULT_MARKER)
            // Neither withheld name appears, so no open row is being
            // rendered that the label could be hanging off.
            ->assertDontSee('Contoh Agus Priyono')
            ->assertDontSee('Contoh Dewi Anggraini')
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

        Livewire::withQueryParams(['tpu' => $this->cemeteryId('tpu-jakarta-menteng')])
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

        Livewire::withQueryParams(['tpu' => $this->cemeteryId('tpu-jakarta-menteng')])
            ->test(GraveSearch::class)
            ->call('search')
            ->assertHasErrors('name')
            ->assertDontSee(self::NO_RESULT_MARKER);
    }

    public function test_an_invalid_death_date_is_a_validation_error(): void
    {
        $this->openTheDataGate();

        Livewire::withQueryParams(['tpu' => $this->cemeteryId('tpu-jakarta-menteng')])
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
                'tpu' => $this->cemeteryId('tpu-jakarta-menteng'),
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

        Livewire::withQueryParams([
            'tpu' => $this->cemeteryId('tpu-jakarta-menteng'),
            'tanggal' => '2018-04-11',
        ])
            ->test(GraveSearch::class)
            ->assertOk()
            ->assertHasNoErrors()
            ->assertSee('Contoh Budi Santoso');
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

        $cemeteryId = $this->cemeteryId('tpu-jakarta-menteng');

        // Safe: RefreshDatabase rolls the whole test transaction back.
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

        foreach (['garbage', "' OR 1=1 --", '../../etc/passwd'] as $tampered) {
            Livewire::withQueryParams(['tpu' => $tampered, 'nama' => 'Contoh'])
                ->test(GraveSearch::class)
                ->assertOk()
                ->assertSee('Pilih TPU/TPS terlebih dahulu.')
                ->assertDontSee('Contoh Budi Santoso');
        }
    }

    /**
     * A draft cemetery must not become searchable by holding on to its id
     * in a URL. `TPS Bekasi Harapan Indah` is seeded `draft` and has one
     * grave record parked in it precisely so this is provable.
     */
    public function test_a_draft_cemetery_cannot_be_searched_through_a_held_url(): void
    {
        $this->openTheDataGate();

        Livewire::withQueryParams([
            'tpu' => $this->cemeteryId('tps-bekasi-harapan-indah'),
            'nama' => 'Contoh',
        ])
            ->test(GraveSearch::class)
            ->assertSee('Pilih TPU/TPS terlebih dahulu.')
            ->assertDontSee('Contoh Rahmat Hidayat');
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

        $component = Livewire::withQueryParams(['tpu' => $this->cemeteryId('tpu-jakarta-menteng')])
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
        $cemeteryId = $this->cemeteryId('tpu-jakarta-menteng');

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
        Livewire::withQueryParams(['tpu' => $this->cemeteryId('tps-jakarta-kemang'), 'nama' => 'Contoh'])
            ->test(GraveSearch::class)
            ->assertSee('/bantuan');
    }
}
