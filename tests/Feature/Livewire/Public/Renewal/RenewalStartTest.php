<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Public\Renewal;

use App\Domain\CemeteryDirectory\CemeteryPublicationStatus;
use App\Domain\CemeteryDirectory\LaunchCityCode;
use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Livewire\Public\Renewal\RenewalStart;
use App\Platform\FeatureGate\Models\FeatureGate;
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
 * status are therefore NOT TESTED here**, and `/perpanjangan` still serves
 * the old stub until those lines land. See `GraveSearchStatesTest`'s doc
 * block for the same note.
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
            ->assertSee('TPU Jakarta Menteng')
            ->assertSee('TPS Jakarta Kemang')
            // A different city's cemeteries must not leak into the list.
            ->assertDontSee('TPU Bogor Bantarjati');
    }

    /**
     * `TPS Bekasi Harapan Indah` is seeded `draft`. Excluding it is real
     * production behaviour from `Cemetery::scopePublished()`, which
     * `CemeteryPublicQuery` composes rather than works around.
     */
    public function test_a_draft_cemetery_is_never_offered(): void
    {
        Livewire::test(RenewalStart::class)
            ->call('selectCity', LaunchCityCode::BEKASI)
            ->assertSee('TPU Bekasi Jatiasih')
            ->assertDontSee('TPS Bekasi Harapan Indah');
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
            // §6.9: a closed gate never removes a required MVP step. City
            // and cemetery selection must still work.
            ->assertSee('Pilih Kota')
            ->assertSee('Jakarta')
            // Never an implication that the record does not exist.
            ->assertSee('Ini bukan berarti data makam yang Anda cari tidak ada.');
    }

    public function test_opening_the_data_gate_removes_the_banner(): void
    {
        FeatureGate::query()->where('gate_id', 'G-DATA-01')->update(['state' => 'open']);

        Livewire::test(RenewalStart::class)
            ->assertDontSee('Pencarian Data Makam Belum Tersedia Online');
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
     * passing silently — which is the correct signal, not a flaw.
     *
     * Safe: RefreshDatabase rolls the whole test transaction back.
     */
    public function test_a_failed_cemetery_read_degrades_honestly_instead_of_500ing(): void
    {
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
