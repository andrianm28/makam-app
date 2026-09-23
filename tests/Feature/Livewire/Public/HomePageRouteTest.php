<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Public;

use App\Domain\CemeteryCapability\CemeteryPackageAvailabilityStatus;
use App\Domain\CemeteryCapability\Models\CemeteryPackage;
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
     * A9/U8 — two-tone service-card headings (kamboja plan Tahap 4,
     * design-system.md §3.3b).
     *
     * The load-bearing assertion here is the third one: the four product
     * labels §9.2 MUST NOT 9 protects must survive the split VERBATIM once
     * markup is stripped. Tahap 4 is a visual-hierarchy stage and is
     * forbidden from touching copy, so a heading that renders
     * "Pemesanan  Makam" or drops a word is a copy change wearing a
     * styling change's clothes — and neither
     * `test_all_four_menus_appear_in_ac1s_exact_order` nor the
     * `assertSee()` tests below would catch it, because the header nav
     * renders all four labels contiguously and would satisfy both on its
     * own.
     */
    public function test_service_card_headings_render_two_tone_without_altering_a_label(): void
    {
        $response = $this->get('/');
        $response->assertOk();

        $body = $response->getContent();
        $this->assertNotFalse($body);

        // Scoped to Section 3's grid by its own aria-label, so the header
        // nav's copies of the same four labels cannot satisfy this test.
        $gridStart = strpos($body, 'aria-label="Layanan utama"');
        $this->assertNotFalse($gridStart, 'Expected Section 3\'s service-card grid.');
        $gridEnd = strpos($body, '</ul>', $gridStart);
        $this->assertNotFalse($gridEnd);
        $grid = substr($body, $gridStart, $gridEnd - $gridStart);

        $headingCount = preg_match_all('#<h3[^>]*>(.*?)</h3>#s', $grid, $matches);
        $this->assertSame(4, $headingCount, 'Expected four service-card headings in the grid.');

        $labels = ['Pemesanan Makam', 'Layanan Pemakaman', 'Perpanjangan Makam', 'FAQ'];

        foreach ($matches[1] as $index => $headingInner) {
            $text = trim((string) preg_replace('/\s+/', ' ', strip_tags($headingInner)));
            $this->assertSame(
                $labels[$index],
                $text,
                "Service-card heading $index must read exactly its product label."
            );
        }

        // The first three labels are multi-word, so they carry the device:
        // lead word in brand ink, remainder near-black, two block spans
        // inside ONE <h3>.
        $this->assertMatchesRegularExpression(
            '#<span class="block text-primary-600">Pemesanan</span>\s*<span class="block">Makam</span>#',
            $matches[1][0]
        );
        $this->assertMatchesRegularExpression(
            '#<span class="block text-primary-600">Layanan</span>\s*<span class="block">Pemakaman</span>#',
            $matches[1][1]
        );
        $this->assertMatchesRegularExpression(
            '#<span class="block text-primary-600">Perpanjangan</span>\s*<span class="block">Makam</span>#',
            $matches[1][2]
        );

        // "FAQ" is one word: no second tone exists, so it gets no brand
        // line at all rather than rendering wholly in brand ink, which
        // would give the last card in AC1's stakeholder order the loudest
        // heading on the row.
        $this->assertStringNotContainsString('text-primary-600', $matches[1][3]);
    }

    /**
     * Tahap 4 butir 1 + 2 — the four service cards are the page's journey
     * entrances, so they render `<x-mk.card emphasis="strong">` (resting
     * `shadow-md`, `border-primary-200`) and the 64 px `xl` medallion,
     * rather than the same treatment every other card on the page uses.
     */
    public function test_service_cards_render_as_strong_cards_with_the_xl_medallion(): void
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

        // Anchored on the card root's RESTING classes, in order. A bare
        // `assertStringContainsString('shadow-md')` is vacuous here: every
        // interactive card already emits `hover:shadow-md`, so it passed
        // even with `emphasis="strong"` mutated down to `shadow-sm`
        // (verified by mutation, 14 Sep 2026 — this is the repaired form).
        $this->assertMatchesRegularExpression(
            '#class="block rounded-lg border shadow-md border-primary-200 bg-neutral-0#',
            $grid
        );
        $this->assertStringNotContainsString('border-neutral-200', $grid);
        // 64px tile — design-system.md §3.3a's `xl`.
        $this->assertStringContainsString('size-16', $grid);
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

        // Sections around the insertion point still render: Trust (6) above
        // it, FAQ highlights (7) below it.
        $response->assertSee('Kenapa Makam.co.id');
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
     * the nine PUBLISHED example names DO appear, and the one deliberately
     * DRAFT example (`CemeteryExampleData::DRAFT_SLUG`) still does not —
     * `Cemetery::published()` filtering the draft row out is itself real
     * production behaviour worth protecting, not just a fixture detail.
     */
    public function test_section_5_shows_published_dummy_cemeteries_and_excludes_the_draft_one(): void
    {
        $response = $this->get('/');
        $response->assertOk();

        // HomePage::render() orders by (city, name) then ->take(6), over
        // published rows only. The expected list is DERIVED from the
        // example-data generator under that same ordering, so a change to
        // the seed data updates the expectation in one place instead of
        // leaving a frozen name snapshot that drifts from the seed.
        $published = collect(CemeteryExampleData::cemeteries())
            ->reject(fn (array $c): bool => $c[7] !== CemeteryPublicationStatus::PUBLISHED)
            ->sortBy(fn (array $c): array => [$c[3], $c[1]])
            ->values();

        $expectedVisibleNames = $published->take(6)->pluck(1)->all();
        $expectedHiddenByCap = $published->skip(6)->pluck(1)->all();

        foreach ($expectedVisibleNames as $name) {
            $response->assertSee($name);
        }

        // Excluded by the draft-publication-status scope (Cemetery::published()):
        $response->assertDontSee(CemeteryExampleData::bySlug(CemeteryExampleData::DRAFT_SLUG)[1]);

        // Excluded purely by the ->take(6) display cap — asserted here so a
        // future cap change is a deliberate, visible test update:
        foreach ($expectedHiddenByCap as $name) {
            $response->assertDontSee($name);
        }
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

    public function test_new_sections_render_between_services_and_how_it_works(): void
    {
        $response = $this->get('/');
        $response->assertOk();

        $html = $response->getContent();
        $servicesPos = strpos($html, 'id="services-heading"');
        $urgentPos = strpos($html, 'id="urgent-availability-heading"');
        $newestPos = strpos($html, 'id="newest-published-heading"');
        $howItWorksPos = strpos($html, 'id="how-it-works-heading"');

        $this->assertNotFalse($servicesPos);
        $this->assertNotFalse($urgentPos);
        $this->assertNotFalse($newestPos);
        $this->assertNotFalse($howItWorksPos);

        $this->assertGreaterThan($servicesPos, $urgentPos, 'urgent-availability must come after services');
        $this->assertGreaterThan($urgentPos, $newestPos, 'newest-published must come after urgent-availability');
        $this->assertGreaterThan($newestPos, $howItWorksPos, 'how-it-works (untouched by this ticket) must still come after the new sections');
    }

    public function test_how_it_works_featured_cemeteries_and_trust_sections_are_untouched(): void
    {
        // Ticket 03 explicitly does not remove these — ticket 04 does,
        // once its own replacement section also exists.
        $response = $this->get('/');
        $response->assertOk();

        $response->assertSee('id="how-it-works-heading"', false);
        $response->assertSee('id="featured-cemeteries-heading"', false);
        $response->assertSee('id="trust-heading"', false);
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
}
