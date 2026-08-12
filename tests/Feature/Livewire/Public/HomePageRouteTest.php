<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Public;

use App\Platform\Analytics\Models\MenuInteractionEvent;
use App\Platform\FeatureGate\Models\FeatureGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * `/` — Sprint 4 S4-T3 `public-home-and-navigation`. Exercises
 * requirements.md AC1 (four menus, exact order), AC3 (Pemesanan Makam is
 * the primary CTA), AC5 (customer-service CTA + truthful Urgent
 * indicator), AC6 (the three not-yet-built destinations get an
 * explanatory 200, never a bare 404), and AC9 (impression recorded,
 * without sensitive data) against real seeded data — never mocked gate
 * state or fabricated fixtures.
 */
final class HomePageRouteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Real HTTP requests below render the full layout (`@vite(...)` in
        // layouts/app.blade.php); this host's CI `php` job has no prior
        // frontend build. Same requirement/reasoning as every other public
        // Livewire route test in this repo (e.g. FaqIndexRouteTest).
        $this->withoutVite();
    }

    public function test_homepage_returns_ok(): void
    {
        $response = $this->get('/');

        $response->assertOk();
    }

    public function test_all_four_menus_appear_in_ac1s_exact_order(): void
    {
        $response = $this->get('/');
        $response->assertOk();

        $body = $response->getContent();
        $this->assertNotFalse($body);

        // Scoped to <body> onward, not the whole raw response: HomePage's
        // own <title> ("Makam.co.id - Pemesanan dan Layanan Pemakaman",
        // set in HomePage::render()'s ->layout() call) contains the literal
        // substring "Layanan Pemakaman" inside <head>, which a whole-page
        // strpos search would find before the header nav ever renders —
        // a real false failure this test hit once already, not a
        // hypothetical. Anchoring on <body onward keeps the search scoped
        // to what AC1's "in this exact order" is actually about: nav
        // order, not page metadata.
        $bodyStart = strpos($body, '<body');
        $this->assertNotFalse($bodyStart, 'Expected a <body> tag in the homepage response.');
        $bodyContent = substr($body, $bodyStart);

        // Plain substring search, not an exact `>FAQ<` tag match: Blade's
        // compiled output has whitespace/newlines around `{{ $item['label'] }}`
        // inside header.blade.php's `<a>` tags, so a strict adjacency match
        // would false-negative. Nothing on this page renders the bare word
        // "FAQ" before the header's own mobile nav panel (the first of the
        // page's several DOM regions to list all four menu labels), so the
        // first occurrence of each substring below still reflects real nav
        // order.
        $positions = [
            'Pemesanan Makam' => strpos($bodyContent, 'Pemesanan Makam'),
            'Layanan Pemakaman' => strpos($bodyContent, 'Layanan Pemakaman'),
            'Perpanjangan Makam' => strpos($bodyContent, 'Perpanjangan Makam'),
            'FAQ' => strpos($bodyContent, 'FAQ'),
        ];

        foreach ($positions as $label => $position) {
            $this->assertNotFalse($position, "Expected to find \"$label\" in the homepage response.");
        }

        // AC1: "in this exact order" — the header nav (first occurrence of
        // each label in the DOM) must preserve stakeholder order.
        $this->assertTrue($positions['Pemesanan Makam'] < $positions['Layanan Pemakaman']);
        $this->assertTrue($positions['Layanan Pemakaman'] < $positions['Perpanjangan Makam']);
        $this->assertTrue($positions['Perpanjangan Makam'] < $positions['FAQ']);
    }

    public function test_pemesanan_makam_is_the_primary_call_to_action(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        // AC3 — the hero's hand-written primary CTA (see home-page.blade.php's
        // own doc block for why it is not <x-mk.button>).
        $response->assertSee('Pesan Makam');
        $response->assertSee('href="/pemesanan-makam"', false);
    }

    public function test_customer_service_cta_is_present(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('Butuh Bantuan Memilih Layanan?');
        $response->assertSee('Hubungi Bantuan');
        $response->assertSee('/bantuan');
    }

    public function test_urgent_banner_shows_the_honest_closed_state_against_the_real_seeded_gate(): void
    {
        // Read the REAL seeded state rather than assuming or mocking it —
        // 2026_07_26_120400_seed_feature_gate_registry.php seeds every gate
        // (including G-OPS-01) closed.
        $gate = FeatureGate::query()->where('gate_id', 'G-OPS-01')->first();
        $this->assertNotNull($gate, 'G-OPS-01 must exist in the seeded feature_gates registry.');
        $this->assertSame('closed', $gate->state, 'This test assumes the real seeded default (closed); update it if that default ever changes.');

        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('Ketersediaan Urgent Belum Dapat Dipastikan Otomatis');
        // Never an acceptance claim (mvp-scope.md §7 / design-system.md §6.9).
        $response->assertDontSee('Urgent tersedia sekarang');
        $response->assertDontSee('Kami menerima permintaan Urgent Anda');
        // design-system.md §7.5 — "every status uses colour + icon +
        // Indonesian text," never colour + text alone. <x-dynamic-component>
        // resolves to the icon's own rendered <svg>, not the component name
        // itself — assert on a distinctive fragment of the actual rendered
        // path data (icon/exclamation-triangle.blade.php's own `d` attribute),
        // not the (never-rendered) string "icon.exclamation-triangle".
        $response->assertSee('M12 9v3.75', false);
    }

    public function test_urgent_banner_is_absent_when_g_ops_01_is_open(): void
    {
        FeatureGate::query()->where('gate_id', 'G-OPS-01')->update(['state' => 'open']);

        $response = $this->get('/');

        $response->assertOk();
        $response->assertDontSee('Ketersediaan Urgent Belum Dapat Dipastikan Otomatis');
    }

    /**
     * RENAMED from `test_section_5_never_renders_any_of_the_s4_t1_seed_
     * fixture_cemetery_names`, which asserted none of these ten names could
     * ever appear on the public homepage — correct at S4-T3 time, when
     * these rows were fictional fixtures with NULL price/photo/coordinates
     * and showing them as "featured" would have misrepresented fabricated
     * content as real (see this file's own prior doc comment, kept in the
     * original PR history).
     *
     * The premise changed by explicit user authorization — see
     * `App\Livewire\Public\HomePage::render()`'s own doc block for the full
     * reasoning trail. Section 5 now deliberately renders these same ten
     * names (nine published, one draft) with clearly-fictional dummy
     * price/photo/coordinate data, for full public display on
     * `dev.makam.co.id`. This test is flipped accordingly: it now asserts
     * the nine PUBLISHED fixture names DO appear, and the one DRAFT fixture
     * (`tps-bekasi-harapan-indah`, per `CemeterySeedTest`) still does not —
     * `Cemetery::published()` filtering the draft row out is itself real
     * production behaviour worth protecting, not just a fixture detail.
     */
    public function test_section_5_shows_published_dummy_cemeteries_and_excludes_the_draft_one(): void
    {
        $response = $this->get('/');
        $response->assertOk();

        // HomePage::render() orders by (city, name) then ->take(6) — with
        // nine published rows across five cities, the cap lands mid-way
        // through Jakarta and excludes Tangerang entirely. This list is the
        // actual first six under that ordering (verified by hand against
        // CemeterySeedTest's known city/name data, not assumed), not just
        // "some six names" — a change to the ordering or cap that shifts
        // this list is exactly the kind of regression this test exists to
        // catch.
        $expectedVisibleNames = [
            'TPU Bekasi Jatiasih',
            'TPS Bogor Cimanggu',
            'TPU Bogor Bantarjati',
            'TPS Depok Cinere',
            'TPU Depok Sawangan',
            'TPS Jakarta Kemang',
        ];

        foreach ($expectedVisibleNames as $name) {
            $response->assertSee($name);
        }

        // Excluded by the draft-publication-status scope (Cemetery::
        // published()), not by the display cap:
        $response->assertDontSee('TPS Bekasi Harapan Indah');

        // Excluded purely by the ->take(6) display cap, even though these
        // are genuinely published rows — asserted here so a future cap
        // change is a deliberate, visible test update, not silent drift:
        $response->assertDontSee('TPU Jakarta Menteng');
        $response->assertDontSee('TPS Tangerang Karawaci');
        $response->assertDontSee('TPU Tangerang Cipondoh');
    }

    public function test_faq_highlights_link_into_the_real_faq_routes(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        // A real seeded, published article (Cara Memesan category).
        $response->assertSee('Bagaimana cara memesan makam?');
        $response->assertSee('href="'.route('faq.show', ['articleSlug' => 'bagaimana-cara-memesan-makam']).'"', false);
        $response->assertSee('href="'.route('faq.index').'"', false);
    }

    public function test_faq_highlights_degrade_gracefully_when_the_faq_query_fails(): void
    {
        // §6.5 provider-unavailable — same technique
        // EloquentGateRegistrySourceTest uses to force a real database
        // failure rather than mock one: FaqIndexRouteTest itself has no
        // test exercising FaqIndex's own try/catch this way, so this is
        // this batch's own construction of that pattern, not a mirror of
        // an existing FAQ test (see this batch's final report).
        //
        // Children dropped first, in reverse-dependency order, instead of
        // `DROP TABLE ... CASCADE`: SQLite's DROP TABLE has no CASCADE
        // clause, and phpunit.xml defaults to SQLite locally while CI runs
        // PostgreSQL — the CASCADE form passed on CI and failed everywhere
        // else. If a later batch adds another table with a foreign key to
        // faq_articles, this test fails with an FK error rather than
        // passing silently — that is the correct signal (same tripwire
        // pattern as RenewalStartTest::test_a_failed_cemetery_read_...).
        // Safe: RefreshDatabase rolls the whole test transaction back.
        Schema::dropIfExists('faq_article_related_article');
        Schema::dropIfExists('faq_article_versions');
        Schema::dropIfExists('faq_articles');

        $response = $this->get('/');

        // The homepage must still render — never a 500 because a secondary
        // panel's query failed (design-system.md §6.3's homepage row).
        $response->assertOk();
        $response->assertSee('Pemesanan Makam');
        $response->assertSee('Layanan Pemakaman');
        $response->assertSee('Perpanjangan Makam');
        $response->assertSee('Pertanyaan populer sedang tidak tersedia');
    }

    /**
     * RENAMED from `test_pemesanan_makam_stub_route_returns_ok_not_404`,
     * which asserted the "Segera Hadir" coming-soon stub's copy. That stopped
     * being true 9 Aug 2026 (S4-T4/S4-T5 resumed, public-booking-wizard
     * Task 9) — `/pemesanan-makam` now serves the real wizard (Steps 1-5),
     * per that stub's own doc block: "expected to be REPLACED wholesale by
     * its owning spec's real routes, not extended in place." Same shape as
     * the marketplace and renewal renames above, both of which hit the same
     * CI failure once their routes were wired.
     *
     * The step-1 heading "Langkah 1 — Pilih Lokasi" would pass through the
     * stepper even if the page body were blank (same reason marketplace-
     * builder and renewal-builder flagged it), so this asserts the wizard's
     * own step-1 subtitle instead — text that renders nowhere else.
     */
    public function test_pemesanan_makam_route_now_serves_the_real_wizard_not_the_stub(): void
    {
        $response = $this->get('/pemesanan-makam');

        $response->assertOk();
        $response->assertSee('Pilih lokasi, TPU/TPS, dan jenis layanan untuk memulai pemesanan makam.');
        $response->assertDontSee('Pemesanan Makam Segera Hadir');
    }

    /**
     * RENAMED from `test_marketplace_stub_route_returns_ok_not_404`, which
     * asserted the "Segera Hadir" coming-soon stub's copy. That stopped
     * being true 8 Aug 2026 (S4-T8, agent-team teammate marketplace-builder,
     * reviewed and wired) — `/marketplace` now serves the real browse page,
     * per that stub's own doc block: "expected to be REPLACED wholesale by
     * its owning spec's real routes, not extended in place." This test
     * belongs to neither S4-T6 nor S4-T8's file ownership (it predates both,
     * from the S4-T3 homepage batch) — fixed here as part of wiring the two
     * routes.php lines both batches were blocked on, the same integration
     * step that broke this assertion. This is the CI failure that caught it:
     * `HomePageRouteTest::test_marketplace_stub_route_returns_ok_not_404`
     * failed on "To contain: Layanan Pemakaman Segera Hadir" once the route
     * changed — expected, not a regression, and the reason this rename
     * exists rather than a silent pass.
     */
    public function test_marketplace_route_now_serves_the_real_browse_page_not_the_stub(): void
    {
        $response = $this->get('/marketplace');

        $response->assertOk();
        $response->assertSee('Layanan Pemakaman');
        $response->assertDontSee('Layanan Pemakaman Segera Hadir');
    }

    /**
     * RENAMED from `test_perpanjangan_stub_route_returns_ok_not_404`, which
     * asserted the "Segera Hadir" coming-soon stub's copy. That stopped
     * being true 8 Aug 2026 (S4-T7, agent-team teammate renewal-builder,
     * reviewed and wired) — `/perpanjangan` now serves the real renewal
     * journey start (Step 1-2). Same shape as the marketplace rename two
     * tests above; both routes were wired in the same integration pass.
     *
     * `assertSee('Perpanjangan Makam')` alone would be weak here for the
     * same reason marketplace-builder flagged on the marketplace rename:
     * `<x-mk.header>` renders that exact string as a nav label on every
     * page, so the assertion would pass even if the page body were blank.
     * `Langkah 1 — Pilih Kota` is this screen's own step-1 heading and
     * appears nowhere else, so it is what actually proves the real page
     * rendered rather than merely a page with a header.
     */
    public function test_perpanjangan_route_now_serves_the_real_renewal_page_not_the_stub(): void
    {
        $response = $this->get('/perpanjangan');

        $response->assertOk();
        $response->assertSee('Langkah 1');
        $response->assertSee('Pilih Kota');
        $response->assertDontSee('Perpanjangan Makam Segera Hadir');
    }

    public function test_viewing_the_homepage_records_menu_impressions_without_sensitive_data(): void
    {
        $this->assertSame(0, MenuInteractionEvent::query()->count());

        $this->get('/')->assertOk();

        $events = MenuInteractionEvent::query()->orderBy('id')->get();

        // AC9 — one impression per primary menu.
        $this->assertSame(4, $events->count());
        $this->assertSame(
            ['pemesanan', 'layanan', 'perpanjangan', 'faq'],
            $events->pluck('menu_key')->all()
        );
        $this->assertTrue($events->every(fn (MenuInteractionEvent $event): bool => $event->interaction === 'impression'));
        $this->assertTrue($events->every(fn (MenuInteractionEvent $event): bool => $event->occurred_at !== null));

        // AC9 "without sensitive data" — no column on this table could even
        // carry a user id, session id, or IP. Asserted against the REAL
        // column list of a row actually fetched back from the database
        // (`getAttributes()` on a freshly-`new`'d, unfetched model would be
        // empty and prove nothing), not just that this one request happened
        // not to populate such a column.
        $columns = array_keys($events->first()->getAttributes());
        $this->assertSame(['id', 'menu_key', 'route', 'interaction', 'occurred_at'], $columns);
        $this->assertSame([], array_intersect($columns, ['user_id', 'session_id', 'ip_address', 'ip', 'user_agent']));
    }
}
