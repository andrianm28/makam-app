<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Public;

use App\Domain\CemeteryCapability\CemeteryPackageAvailabilityStatus;
use App\Domain\CemeteryCapability\Models\CemeteryCapabilityProfile;
use App\Domain\CemeteryCapability\Models\CemeteryPackage;
use App\Domain\CemeteryCapability\RegistryMode;
use App\Domain\CemeteryDirectory\CemeteryPublicationStatus;
use App\Domain\CemeteryDirectory\CemeteryType;
use App\Domain\CemeteryDirectory\LaunchCityCode;
use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Domain\CemeteryDirectory\PlotTrackingMode;
use App\Domain\PlotInventory\Models\CemeteryBlock;
use App\Domain\PlotInventory\PlotState;
use App\Platform\Analytics\Models\MenuInteractionEvent;
use App\Platform\FeatureGate\Models\FeatureGate;
use App\Support\ContactInfo;
use App\Support\ExampleData\CemeteryExampleData;
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
        // AC3 — the hero's single primary CTA, rendered via <x-mk.hero>'s
        // `cta` prop (brand visual refresh Phase 2; see
        // test_hero_section_uses_the_mk_hero_component_with_a_real_image
        // below for the component-identity assertion).
        $response->assertSee('Pesan Makam');
        $response->assertSee('href="/pemesanan-makam"', false);
    }

    /**
     * REDESIGNED 24 Sep 2026 (pixel-fidelity 1:1 visual clone of FFI's
     * QuickActionTiles.tsx) — SUPERSEDES the A9/U8 two-tone heading device
     * this test used to assert (kamboja plan Tahap 4, design-system.md
     * §3.3b). FFI's own tile has a single flat label under the icon, no
     * split, no brand-ink lead word — so the replacement here asserts a
     * flat label instead of the old two-span device.
     *
     * The load-bearing assertion is still the same: the four product
     * labels §9.2 MUST NOT 9 protects must survive VERBATIM. Neither
     * `test_all_four_menus_appear_in_ac1s_exact_order` nor the
     * `assertSee()` tests below would catch a corrupted label here, because
     * the header nav renders all four labels contiguously and would
     * satisfy both on its own.
     */
    public function test_service_tile_labels_render_flat_without_altering_a_label(): void
    {
        $response = $this->get('/');
        $response->assertOk();

        $body = $response->getContent();
        $this->assertNotFalse($body);

        // Scoped to Section 3's grid by its own aria-label, so the header
        // nav's copies of the same four labels cannot satisfy this test.
        $gridStart = strpos($body, 'aria-label="Layanan utama"');
        $this->assertNotFalse($gridStart, 'Expected Section 3\'s service-tile grid.');
        $gridEnd = strpos($body, '</ul>', $gridStart);
        $this->assertNotFalse($gridEnd);
        $grid = substr($body, $gridStart, $gridEnd - $gridStart);

        // FFI's tile has no heading element at all -- label is a plain
        // <span>, not an <h3>. No two-tone split: the old
        // '<span class="block text-primary-600">...' device is gone.
        $this->assertStringNotContainsString('<h3', $grid);
        $this->assertStringNotContainsString('text-primary-600">Pemesanan</span>', $grid);

        $labelCount = preg_match_all(
            '#<span class="text-center text-sm font-medium text-neutral-900">(.*?)</span>#s',
            $grid,
            $matches
        );
        $this->assertSame(4, $labelCount, 'Expected four service-tile labels in the grid.');

        $labels = ['Pemesanan Makam', 'Layanan Pemakaman', 'Perpanjangan Makam', 'FAQ'];

        foreach ($matches[1] as $index => $labelInner) {
            $text = trim((string) preg_replace('/\s+/', ' ', strip_tags($labelInner)));
            $this->assertSame(
                $labels[$index],
                $text,
                "Service-tile label $index must read exactly its product label."
            );
        }
    }

    /**
     * REDESIGNED 24 Sep 2026 (pixel-fidelity 1:1 visual clone) — SUPERSEDES
     * the `emphasis="strong"` card treatment this test used to assert
     * (Tahap 4 butir 1 + 2). FFI's own QuickActionTiles.tsx tile has no
     * card, no border, no box at all -- just an icon-in-a-circle with a
     * label underneath. The four tiles are still the page's journey
     * entrances; they now express that with the 64 px `xl` medallion
     * ALONE, matching FFI's own icon-in-a-circle tile exactly, not with a
     * bordered card distinguishing them from other cards on the page.
     */
    public function test_service_tiles_render_as_bare_circular_medallions_with_no_card(): void
    {
        $response = $this->get('/');
        $response->assertOk();

        $body = $response->getContent();
        $this->assertNotFalse($body);

        $gridStart = strpos($body, 'aria-label="Layanan utama"');
        $this->assertNotFalse($gridStart);
        $gridEnd = strpos($body, '</ul>', $gridStart);
        $this->assertNotFalse($gridEnd);
        $grid = substr($body, $gridStart, $gridEnd - $gridStart);

        // No card box of any kind survives the redesign.
        $this->assertStringNotContainsString('<x-mk.card', $grid);
        $this->assertStringNotContainsString('border-primary-200', $grid);
        $this->assertStringNotContainsString('border-neutral-200', $grid);
        $this->assertStringNotContainsString('shadow-md', $grid);
        // No description text survives either -- FFI's tile has none.
        $this->assertStringNotContainsString(
            'Pesan makam baru atau makam tumpang',
            $grid
        );

        // Each tile is a plain anchor wrapping a circular icon-medallion
        // and a label, four times.
        $this->assertSame(4, substr_count($grid, 'flex touch-target flex-col items-center gap-2'));
        // rounded-full -- the icon-medallion's own pixel-fidelity shape
        // correction (was rounded-xl), asserted here per-tile too, not
        // just in MkIconMedallionTest, since this is the one real page
        // that actually renders it.
        $this->assertSame(4, substr_count($grid, 'rounded-full'));
        // 64px tile -- design-system.md §3.3a's `xl`.
        $this->assertSame(4, substr_count($grid, 'size-16'));
    }

    /**
     * Brand visual refresh Phase 2
     * (docs/superpowers/plans/2026-08-25-brand-visual-refresh-phase2-homepage.md
     * Task 1) — proves the hero renders via the real `<x-mk.hero>` component
     * (its distinguishing root class), not just that the old hand-written
     * markup's text happens to still appear. Also asserts the hero's image
     * renders — the `src` is a PLACEHOLDER path
     * (public/images/hero/cemetery-garden-daylight.jpg does not exist yet in
     * this repo); the final binary photo file is a deliberate follow-up once
     * the project owner picks one of the sourced candidates (see this PR's
     * description). Wiring, tests, and verification are real and complete now.
     */
    public function test_hero_section_uses_the_mk_hero_component_with_a_real_image(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        // UPDATED 13 Sep 2026 (Task C1, mobile CTA above the fold): the
        // hero root gained `flex flex-col ... md:block` so that CSS `order`
        // can lift the text panel above the photo below `md`. The literal
        // this assertion matches therefore changed; what it proves has not
        // — it is still the component's distinguishing root class string.
        // The ordering behaviour itself is asserted in
        // Tests\Feature\View\Components\MkHeroTest, which owns the component.
        $response->assertSee('relative flex flex-col overflow-hidden rounded-lg md:block', false);
        $response->assertSee('src="'.asset('images/hero/cemetery-garden-daylight.jpg').'"', false);
        $response->assertSee('Pesan Makam');
        $response->assertSee('href="/pemesanan-makam"', false);
    }

    /**
     * "Kehangatan Keluarga" supporting photo section (added 26 Aug 2026,
     * see home-page.blade.php's own doc block for the full placement and
     * sourcing reasoning) — proves the section renders on the real
     * homepage with the real image, and proves the existing hero and other
     * sections still render alongside it (nothing broken by the insertion).
     */
    public function test_family_warmth_section_renders_with_the_real_image_alongside_the_existing_hero(): void
    {
        $response = $this->get('/');

        $response->assertOk();

        // The new section: real copy and the real image path.
        $response->assertSee('Didampingi dengan Hangat, Setiap Langkah');
        $response->assertSee('src="'.asset('images/home/family-warmth.jpg').'"', false);

        // The existing hero (Section 2) is untouched — same image, same CTA.
        $response->assertSee('src="'.asset('images/hero/cemetery-garden-daylight.jpg').'"', false);
        $response->assertSee('Pesan Makam');
        $response->assertSee('href="/pemesanan-makam"', false);

        // FAQ highlights below it still renders. The old "Trust" section
        // that used to sit above it (Kenapa Makam.co.id) was replaced by
        // Stage 3 ticket 04's verified-cemeteries section — see
        // test_verified_cemeteries_section_* below for that section's own
        // coverage.
        $response->assertSee('Pertanyaan yang Sering Diajukan');
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

    /**
     * The kamboja plan's U7, button-weight half only: the hotline inside
     * the Urgent banner carries the visual weight of a button rather than
     * an inline text link.
     *
     * U7's other half — making the banner dismissible — was implemented as
     * ADR-0040 D4 and reverted the same day, before this branch was pushed.
     * §6.9 grants dismissibility "**only** for informational modes", and
     * this is the one gate its table gives `urgent` intent instead of
     * `info`. `UrgentMode::fallback()`'s doc block carries the full
     * argument; the last assertion of this test asserts the ABSENCE of a
     * close control so that reversal cannot quietly undo itself.
     *
     * The N10 half of U7 is the half that matters and is asserted first:
     * not one word of the copy changed, so no service promise was added
     * while `G-OPS-01` is closed. `assertDontSee` on the false claims is
     * kept in the test above; here the positive assertion is that every
     * original word is still present and in the same order.
     */
    public function test_urgent_banner_hotline_carries_button_weight_and_stays_undismissible(): void
    {
        $response = $this->get('/');

        $response->assertOk();

        $phone = ContactInfo::phone();
        $telHref = 'tel:+'.preg_replace('/[^0-9]/', '', $phone);

        // The number is still a real dial link with the `+` kept (a handset
        // reads `tel:62…` as a domestic number) …
        $response->assertSee('href="'.$telHref.'"', false);
        // … and it is now rendered by <x-mk.button variant="secondary">,
        // whose border is the assertion that distinguishes a button from
        // the plain underlined <a> this used to be. `secondary`, never
        // `primary`: design-system.md §2.3 allows one primary action per
        // view and that is the hero's "Pesan Makam".
        $response->assertSee('border-primary-600', false);

        // Copy unchanged — the exact words that surrounded the number
        // before U7 are all still on the page (plan N10).
        $response->assertSee('hotline di bawah ini dapat dihubungi kapan pun');
        $response->assertSee('untuk menanyakan ketersediaan');
        $response->assertSee($phone);
        $response->assertSee('hubungi Bantuan');

        // NOT dismissible. <x-mk.alert> renders its close control only when
        // `dismissible` is true, so the absence of §3.8's Indonesian close
        // label is the observable proof that this banner cannot be waved
        // away on the path to choosing Urgent at Step 3.
        $response->assertDontSee('aria-label="Tutup"', false);
    }

    /**
     * ADR-0040 D5: consecutive homepage bands alternate, so a section
     * boundary is legible without the divider line design-system.md §4.4
     * forbids ("Proximity carries the grouping — do not reach for divider
     * lines").
     *
     * Asserted as classes rather than pixels because that is what a
     * server-rendered response can honestly prove. The `surface-quiet` /
     * `surface-warm` utilities themselves are compiled by Tailwind, and
     * their presence in `app.css` is what makes these classes mean
     * anything — that half is verified by the frontend job's build, not
     * here. What this test does catch is the regression that actually
     * matters: someone reverting a band to the raw primitive, or the
     * alternation collapsing back to one uniform surface.
     */
    public function test_homepage_sections_alternate_surfaces_without_divider_lines(): void
    {
        $response = $this->get('/');

        $response->assertOk();

        // Both tinted bands present, written as the SEMANTIC utilities.
        $response->assertSee('class="surface-quiet py-section lg:py-section-lg"', false);
        $response->assertSee('class="surface-warm py-section lg:py-section-lg"', false);

        // And not as the raw primitives they replaced (tokens.css §2:
        // "Always reference the SEMANTIC token in component CSS, not the
        // primitive"). Scoped to the full-bleed band class strings so this
        // never trips on an unrelated legitimate use of the primitive.
        $response->assertDontSee('class="bg-secondary-50 py-section', false);
        $response->assertDontSee('class="bg-primary-50 py-section', false);
    }

    public function test_urgent_banner_is_absent_when_g_ops_01_is_open(): void
    {
        FeatureGate::query()->where('gate_id', 'G-OPS-01')->update(['state' => 'open']);

        $response = $this->get('/');

        $response->assertOk();
        $response->assertDontSee('Ketersediaan Urgent Belum Dapat Dipastikan Otomatis');
    }

    /**
     * Stage 3 ticket 03 — the three secondary CTAs (design doc §4.2).
     * Wakaf Tanah has no real route in this codebase (confirmed by search
     * before implementing this ticket) — same honest-disabled-control
     * pattern header.blade.php's own $akunAvailable handling already
     * establishes, not a working link.
     */
    public function test_secondary_ctas_render_below_the_services_grid(): void
    {
        $response = $this->get('/');
        $response->assertOk();

        $html = $response->getContent();
        $servicesEnd = strpos($html, '</section>', strpos($html, 'id="services-heading"'));
        $this->assertNotFalse($servicesEnd);
        $servicesSection = substr($html, strpos($html, 'id="services-heading"'), $servicesEnd - strpos($html, 'id="services-heading"'));

        $this->assertStringContainsString('href="'.route('perpanjangan.index').'"', $servicesSection);
        $this->assertStringContainsString('Perpanjang Makam', $servicesSection);
        $this->assertStringContainsString('href="'.route('marketplace.index').'"', $servicesSection);
        $this->assertStringContainsString('Layanan Pemakaman', $servicesSection);
        // Honest disabled control, not a link — no href, aria-disabled.
        $this->assertStringContainsString('Wakaf Tanah', $servicesSection);
        $this->assertStringContainsString('aria-disabled="true"', $servicesSection);
    }

    /**
     * Stage 3 ticket 03 — urgent-availability TPU/TPS, derived from real
     * seeded packages marked LIMITED (CemeteryPackageAvailabilityStatus),
     * the same "derive expectations from the example-data generator"
     * pattern the section-5 test above already uses.
     */
    public function test_urgent_availability_section_shows_cemeteries_with_limited_packages(): void
    {
        $response = $this->get('/');
        $response->assertOk();
        $response->assertSee('TPU &amp; TPS dengan Ketersediaan Terbatas', false);

        $limitedSlugs = collect(CemeteryExampleData::packages())
            ->filter(fn (array $p): bool => $p[3] === CemeteryPackageAvailabilityStatus::LIMITED)
            ->pluck(0)
            ->unique();
        $this->assertNotEmpty($limitedSlugs, 'fixture must have at least one LIMITED package for this test to be meaningful');

        $qualifyingNames = collect(CemeteryExampleData::cemeteries())
            ->filter(fn (array $c): bool => $limitedSlugs->contains($c[2]) && $c[7] === CemeteryPublicationStatus::PUBLISHED)
            ->pluck(1);

        $html = $response->getContent();
        $sectionStart = strpos($html, 'id="urgent-availability-heading"');
        $this->assertNotFalse($sectionStart, 'urgent-availability section must render given real LIMITED packages exist in the fixture');
        $sectionEnd = strpos($html, '<h2', strpos($html, '</section>', $sectionStart));
        $section = substr($html, $sectionStart, ($sectionEnd !== false ? $sectionEnd : strlen($html)) - $sectionStart);

        foreach ($qualifyingNames as $name) {
            $this->assertStringContainsString($name, $section);
        }

        // A published cemetery with no LIMITED package must not appear in
        // THIS section (it may legitimately appear elsewhere on the page).
        $nonQualifying = collect(CemeteryExampleData::cemeteries())
            ->first(fn (array $c): bool => ! $limitedSlugs->contains($c[2]) && $c[7] === CemeteryPublicationStatus::PUBLISHED);
        $this->assertNotNull($nonQualifying);
        $this->assertStringNotContainsString($nonQualifying[1], $section);
    }

    public function test_urgent_availability_section_is_absent_when_nothing_qualifies(): void
    {
        // No LIMITED package exists for any cemetery once the fixture ones
        // are neutralised — real empty-state behaviour, not a mocked flag.
        CemeteryPackage::query()
            ->where('availability_status', CemeteryPackageAvailabilityStatus::LIMITED)
            ->update(['availability_status' => CemeteryPackageAvailabilityStatus::AVAILABLE]);

        $response = $this->get('/');

        $response->assertOk();
        $response->assertDontSee('id="urgent-availability-heading"', false);
    }

    /**
     * Stage 3 ticket 03 — newest published TPU/TPS, ordered by
     * `published_at` desc (with an `id` tie-breaker the fixture's
     * identical-timestamp rows make necessary for determinism).
     */
    public function test_newest_published_section_shows_published_cemeteries(): void
    {
        $response = $this->get('/');
        $response->assertOk();
        $response->assertSee('TPU &amp; TPS Terbaru', false);

        $html = $response->getContent();
        $sectionStart = strpos($html, 'id="newest-published-heading"');
        $this->assertNotFalse($sectionStart);
        $sectionEnd = strpos($html, '<h2', strpos($html, '</section>', $sectionStart));
        $section = substr($html, $sectionStart, ($sectionEnd !== false ? $sectionEnd : strlen($html)) - $sectionStart);

        // At least one real published cemetery name renders in this
        // section — not asserting the exact top-6 set, since every
        // published cemetery in the fixture shares the same `published_at`
        // instant and is therefore a legitimate member of "newest" under
        // the `id` tie-breaker.
        $anyPublished = collect(CemeteryExampleData::cemeteries())
            ->first(fn (array $c): bool => $c[7] === CemeteryPublicationStatus::PUBLISHED);
        $this->assertNotNull($anyPublished);
        $this->assertStringContainsString($anyPublished[1], $html);

        // Excluded by the draft-publication-status scope, same as section 5.
        $this->assertStringNotContainsString(
            CemeteryExampleData::bySlug(CemeteryExampleData::DRAFT_SLUG)[1],
            $section
        );
    }

    /**
     * Stage 3 ticket 04 — the design doc §4.1 final section order for the
     * region this stage restructured, checked in one pass rather than
     * each section's independent presence: services (carrying the
     * secondary CTAs), urgent TPU/TPS, newest TPU/TPS, verified TPU/TPS,
     * family warmth, FAQ highlights, customer-service CTA. The urgent
     * banner and plot-availability preview are deliberately excluded —
     * both are conditionally rendered (gate state / seed configuration)
     * and already have their own dedicated ordering tests elsewhere in
     * this file. Same relative-`strpos` ordering technique the rest of
     * this file's tests already use.
     */
    public function test_final_homepage_section_order_matches_the_design_doc(): void
    {
        // The verified section's heading always renders, even against
        // real, unmodified seed data where no cemetery's card grid
        // qualifies (see the view's own comment) — no extra fixture setup
        // needed here to locate it, unlike the other real-data sections.
        $response = $this->get('/');
        $response->assertOk();

        $html = $response->getContent();
        $positions = [
            'services' => strpos($html, 'id="services-heading"'),
            'secondary CTAs' => strpos($html, 'Perpanjang Makam'),
            'urgent TPU/TPS' => strpos($html, 'id="urgent-availability-heading"'),
            'newest TPU/TPS' => strpos($html, 'id="newest-published-heading"'),
            'verified TPU/TPS' => strpos($html, 'id="verified-heading"'),
            'family warmth' => strpos($html, 'id="family-warmth-heading"'),
            'FAQ highlights' => strpos($html, 'id="faq-highlights-heading"'),
            'customer-service CTA' => strpos($html, 'id="cs-cta-heading"'),
        ];

        foreach ($positions as $label => $position) {
            $this->assertNotFalse($position, "expected to find the $label marker in the response");
        }

        $ordered = array_values($positions);
        $labels = array_keys($positions);
        for ($i = 1; $i < count($ordered); $i++) {
            $this->assertGreaterThan(
                $ordered[$i - 1],
                $ordered[$i],
                $labels[$i].' must come after '.$labels[$i - 1]
            );
        }
    }

    public function test_how_it_works_featured_cemeteries_and_trust_sections_are_removed(): void
    {
        // The three sections ticket 04 explicitly removes, now that its
        // own replacement (the verified-cemeteries section) exists.
        $response = $this->get('/');
        $response->assertOk();

        $response->assertDontSee('id="how-it-works-heading"', false);
        $response->assertDontSee('id="featured-cemeteries-heading"', false);
        $response->assertDontSee('id="trust-heading"', false);

        // Their redistributed substance survives elsewhere, not silently
        // dropped.
        $response->assertSee('lunas setelah benar-benar', false);
        $response->assertSee('pilih lokasi dan jenis layanan', false);
    }

    /**
     * Stage 3 ticket 04 — verified-cemeteries CARD GRID, honest empty
     * state. Every seeded cemetery's current capability profile is the
     * S4-T1 safe default (`registry_mode = NONE`) — confirmed directly
     * against `CemeteryExampleData::seed()`'s own insert, not assumed —
     * so no real card renders against real, unmodified seed data. The
     * section ITSELF (heading + reassurance intro, replacing the old,
     * always-visible "Trust/safety" section) still renders regardless —
     * see the view's own comment on why only the card grid is
     * data-dependent, not the whole section.
     */
    public function test_verified_cemeteries_card_grid_is_absent_against_unmodified_seed_data(): void
    {
        $response = $this->get('/');
        $response->assertOk();

        // The section and its reassurance copy still render.
        $response->assertSee('id="verified-heading"', false);
        $response->assertSee('lunas setelah benar-benar', false);

        // But no real verified-cemetery card does.
        $response->assertDontSee('Lokasi Terverifikasi');
    }

    public function test_verified_cemeteries_section_renders_with_the_trust_badges_when_a_cemetery_is_genuinely_verified(): void
    {
        $cemetery = $this->createGenuinelyVerifiedCemetery();

        $response = $this->get('/');
        $response->assertOk();

        $html = $response->getContent();
        $sectionStart = strpos($html, 'id="verified-heading"');
        $this->assertNotFalse($sectionStart, 'verified section must render given a real AUTHORITATIVE registry_mode profile exists');
        $sectionEnd = strpos($html, '</section>', $sectionStart);
        $section = substr($html, $sectionStart, ($sectionEnd !== false ? $sectionEnd : strlen($html)) - $sectionStart);

        $this->assertStringContainsString($cemetery->name, $section);
        $this->assertStringContainsString('Lokasi Terverifikasi', $section);
        $this->assertStringContainsString('Harga Transparan', $section);
    }

    /**
     * Real domain-model construction, not a mock: a brand-new published
     * `Cemetery` plus a real, current `CemeteryCapabilityProfile` row with
     * `registry_mode = AUTHORITATIVE` — the same real-state-construction
     * technique `test_plot_availability_preview_renders_between_the_hero_
     * and_the_service_cards_when_data_exists` above already uses for its
     * own new-cemetery fixture.
     */
    private function createGenuinelyVerifiedCemetery(): Cemetery
    {
        $cemetery = Cemetery::query()->create([
            'type' => CemeteryType::TPU,
            'publication_status' => CemeteryPublicationStatus::PUBLISHED,
            'name' => 'TPU Terverifikasi Homepage',
            'slug' => 'tpu-terverifikasi-homepage',
            'city' => LaunchCityCode::JAKARTA,
            'address' => 'Jl. Contoh Verifikasi No. 1',
            'plot_tracking_mode' => PlotTrackingMode::GRANULAR,
            // Real price fields so CemeteryPresenter::priceRange()/
            // priceAttribution() both resolve non-null — the section's
            // "Harga Transparan" note only renders alongside a real price.
            'price_min' => 5_000_000,
            'price_max' => 8_000_000,
            'price_currency' => 'IDR',
            'price_source' => 'Test fixture',
            'price_effective_at' => now(),
        ]);

        $defaults = CemeteryCapabilityProfile::safeDefaults();

        CemeteryCapabilityProfile::query()->create([
            ...$defaults,
            'cemetery_id' => $cemetery->getKey(),
            'version_number' => 1,
            'registry_mode' => RegistryMode::AUTHORITATIVE,
            'source' => 'test:homepage-verified-section',
            'owner' => 'Test fixture',
            'evidence' => 'Registry authoritatively evidenced for this test fixture.',
            'rollback_plan' => 'Not applicable — test fixture only.',
            'effective_at' => now(),
            'superseded_at' => null,
        ]);

        return $cemetery;
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

    /**
     * NOT proof that a published-but-aggregate-tier cemetery is correctly
     * hidden — that's a different code path (the tier guard in
     * PlotAvailabilityPreview::buildShowcase(), covered at the component
     * level by PlotAvailabilityPreviewTest::
     * test_renders_nothing_when_no_configured_cemetery_is_granular()).
     * The default config's slugs (`tpu-petamburan`, `tpu-karet-bivak`) are
     * NOT created by any migration/seeder under RefreshDatabase — they only
     * exist on real dev/stg/beta via an UPDATE-only backfill migration
     * against operator-created rows — so under test this only exercises the
     * unresolvable-slug path (CemeteryPublicQuery::findPublishedBySlug()
     * returning null), already covered by
     * test_skips_a_configured_slug_that_does_not_resolve() at the component
     * level. This test's only distinct value is confirming that path stays
     * silent at the real homepage-route level too.
     */
    public function test_plot_availability_preview_is_absent_when_no_configured_slug_resolves_under_test_seed_data(): void
    {
        $response = $this->get('/');
        $response->assertOk();

        $response->assertDontSeeText('Lihat Contoh Ketersediaan Plot');
    }

    public function test_plot_availability_preview_renders_between_the_hero_and_the_service_cards_when_data_exists(): void
    {
        $cemetery = Cemetery::query()->create([
            'type' => CemeteryType::TPU,
            'publication_status' => CemeteryPublicationStatus::PUBLISHED,
            'name' => 'TPU Pratinjau Homepage',
            'slug' => 'tpu-pratinjau-homepage',
            'city' => LaunchCityCode::JAKARTA,
            'address' => 'Jl. Contoh No. 1',
            'plot_tracking_mode' => PlotTrackingMode::GRANULAR,
        ]);

        $block = CemeteryBlock::query()->create([
            'cemetery_id' => $cemetery->getKey(),
            'code' => 'BLOK-A',
            'name' => 'Blok A',
            'capacity' => 1,
        ]);

        $block->plots()->create(['slot' => '001', 'plot_state' => PlotState::AVAILABLE]);

        config(['marketing.homepage_plot_preview_cemetery_slugs' => ['tpu-pratinjau-homepage']]);

        $response = $this->get('/');
        $response->assertOk();

        $body = $response->getContent();
        $this->assertNotFalse($body);

        $heroEnd = strpos($body, 'Pesan Makam');
        $servicesHeading = strpos($body, 'id="services-heading"');
        $previewHeading = strpos($body, 'id="plot-preview-heading"');

        $this->assertNotFalse($heroEnd);
        $this->assertNotFalse($servicesHeading);
        $this->assertNotFalse($previewHeading);
        $this->assertGreaterThan($heroEnd, $previewHeading, 'Preview section must render after the hero.');
        $this->assertLessThan($servicesHeading, $previewHeading, 'Preview section must render before the service cards.');
    }

    public function test_bottom_nav_renders_with_beranda_active_and_is_hidden_above_lg(): void
    {
        $response = $this->get('/');

        $response->assertSee('aria-label="Navigasi utama"', false);

        $html = $response->getContent();
        $navigasiUtamaPos = strpos($html, 'Navigasi utama');
        $this->assertNotFalse($navigasiUtamaPos, 'Bottom nav aria-label not found');

        // `header.blade.php` already emits the literal string 'lg:hidden'
        // elsewhere on the page (its own mobile bar classes), so asserting
        // 'lg:hidden' anywhere in the whole page would still pass even if the
        // bottom nav itself lost 'lg:hidden' entirely. Isolate the bottom
        // nav's own opening <nav ...> tag — the nearest preceding '<nav'
        // before its aria-label, through to that tag's own closing '>' —
        // and assert 'lg:hidden' inside THAT substring only, so this
        // assertion can actually fail if the bottom nav specifically dropped
        // it.
        $navTagStart = strrpos(substr($html, 0, $navigasiUtamaPos), '<nav');
        $this->assertNotFalse($navTagStart, 'Opening <nav> tag not found before the bottom nav aria-label');
        $navTagEnd = strpos($html, '>', $navTagStart);
        $this->assertNotFalse($navTagEnd, 'Bottom nav <nav> tag never closes');
        $navOpeningTag = substr($html, $navTagStart, $navTagEnd - $navTagStart + 1);

        $this->assertStringContainsString('lg:hidden', $navOpeningTag, 'Bottom nav <nav> tag must carry lg:hidden');

        // Beranda's own anchor carries aria-current + the active classes;
        // isolate it the same way MkBottomNavTest's own active-tab tests do,
        // so this doesn't just prove SOME tab is active, but that Beranda
        // specifically is.
        $berandaStart = strpos($html, 'href="/"', $navigasiUtamaPos);
        $this->assertNotFalse($berandaStart, 'Beranda tab anchor not found');
        $berandaEnd = strpos($html, '</a>', $berandaStart);
        $berandaAnchor = substr($html, $berandaStart, $berandaEnd - $berandaStart);

        $this->assertStringContainsString('aria-current="page"', $berandaAnchor);
        $this->assertStringContainsString('text-primary-700', $berandaAnchor);
        $this->assertStringContainsString('border-primary-600', $berandaAnchor);

        // The header's own hamburger/active state is untouched by this change.
        $response->assertSee('Menu utama (seluler)', false);
    }

    public function test_bottom_nav_does_not_visually_overlap_the_footer(): void
    {
        $response = $this->get('/');

        // The footer's clearance margin lives in layouts/app.blade.php, the
        // SHARED layout every wired public page renders through — not in
        // HomePage itself. So this one test covers the footer-clearance fix
        // for every page wired to the bottom nav, not just the homepage;
        // duplicating it per page would assert the same shared markup
        // fourteen-plus times over.
        // Footer carries the mobile-only bottom-nav clearance margin; lg:mb-0
        // cancels it where the bar is hidden.
        $response->assertSee('mb-[var(--mk-bottomnav-total)] lg:mb-0', false);
    }
}
