<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Public;

use App\Platform\Analytics\Models\MenuInteractionEvent;
use App\Platform\FeatureGate\Models\FeatureGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

        // Plain substring search, not an exact `>FAQ<` tag match: Blade's
        // compiled output has whitespace/newlines around `{{ $item['label'] }}`
        // inside header.blade.php's `<a>` tags, so a strict adjacency match
        // would false-negative. Nothing on this page renders the bare word
        // "FAQ" before the header's own mobile nav panel (the first of the
        // page's several DOM regions to list all four menu labels), so the
        // first occurrence of each substring below still reflects real nav
        // order.
        $positions = [
            'Pemesanan Makam' => strpos($body, 'Pemesanan Makam'),
            'Layanan Pemakaman' => strpos($body, 'Layanan Pemakaman'),
            'Perpanjangan Makam' => strpos($body, 'Perpanjangan Makam'),
            'FAQ' => strpos($body, 'FAQ'),
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

    public function test_section_5_never_renders_any_of_the_s4_t1_seed_fixture_cemetery_names(): void
    {
        // The single most important negative assertion in this batch — see
        // App\Livewire\Public\HomePage::render()'s own doc block for the
        // full reasoning. All ten are real rows in the database right now
        // (2026_07_26_190300_seed_cemeteries_and_capability_profiles.php);
        // none of them may ever appear on the public homepage.
        $response = $this->get('/');
        $response->assertOk();

        $fixtureCemeteryNames = [
            'TPU Jakarta Menteng',
            'TPS Jakarta Kemang',
            'TPU Bogor Bantarjati',
            'TPS Bogor Cimanggu',
            'TPU Depok Sawangan',
            'TPS Depok Cinere',
            'TPU Tangerang Cipondoh',
            'TPS Tangerang Karawaci',
            'TPU Bekasi Jatiasih',
            'TPS Bekasi Harapan Indah',
        ];

        foreach ($fixtureCemeteryNames as $name) {
            $response->assertDontSee($name);
        }
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
        // CASCADE: faq_article_versions and faq_article_related_article
        // both hold FK constraints against faq_articles. Safe here since
        // RefreshDatabase rolls the whole test transaction back afterwards.
        DB::statement('DROP TABLE faq_articles CASCADE');

        $response = $this->get('/');

        // The homepage must still render — never a 500 because a secondary
        // panel's query failed (design-system.md §6.3's homepage row).
        $response->assertOk();
        $response->assertSee('Pemesanan Makam');
        $response->assertSee('Layanan Pemakaman');
        $response->assertSee('Perpanjangan Makam');
        $response->assertSee('Pertanyaan populer sedang tidak tersedia');
    }

    public function test_pemesanan_makam_stub_route_returns_ok_not_404(): void
    {
        $response = $this->get('/pemesanan-makam');

        $response->assertOk();
        $response->assertSee('Pemesanan Makam Segera Hadir');
        $response->assertSee('Pemesanan Makam');
    }

    public function test_marketplace_stub_route_returns_ok_not_404(): void
    {
        $response = $this->get('/marketplace');

        $response->assertOk();
        $response->assertSee('Layanan Pemakaman Segera Hadir');
    }

    public function test_perpanjangan_stub_route_returns_ok_not_404(): void
    {
        $response = $this->get('/perpanjangan');

        $response->assertOk();
        $response->assertSee('Perpanjangan Makam Segera Hadir');
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
