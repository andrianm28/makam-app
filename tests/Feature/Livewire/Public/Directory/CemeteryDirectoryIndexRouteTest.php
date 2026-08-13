<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Public\Directory;

use App\Domain\CemeteryDirectory\CemeteryPublicationStatus;
use App\Domain\CemeteryDirectory\CemeteryType;
use App\Domain\CemeteryDirectory\LaunchCityCode;
use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Livewire\Public\Directory\CemeteryDirectoryIndex;
use App\Livewire\Public\Directory\Support\CemeteryAvailabilityIntent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * `/cemeteries` (route name `cemeteries.index`) — Sprint 4 S4-T6, AC1,
 * AC2, AC3, AC5, AC11, AC12.
 *
 * Every URL here is built with `route('cemeteries.index')` rather than a
 * literal path, so this file keeps passing if the path registered in
 * `routes/web.php` changes. It does depend on the route NAME existing —
 * `routes/web.php` is outside this batch's scope and the required line is
 * named in the batch report.
 */
final class CemeteryDirectoryIndexRouteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Required for any real HTTP request rendering a layout with
        // @vite(...) — CI's `php` job has no frontend build.
        $this->withoutVite();
    }

    public function test_the_directory_route_renders_successfully(): void
    {
        $this->get(route('cemeteries.index'))
            ->assertOk()
            ->assertSee('Direktori TPU dan TPS');
    }

    /**
     * AC1 + the negative criterion "No hidden omission of a required MVP
     * city". All five launch cities must appear as filter controls
     * regardless of how many cemeteries each currently has.
     */
    public function test_all_five_launch_cities_are_offered_as_filters(): void
    {
        $response = $this->get(route('cemeteries.index'));
        $body = $this->body($response->getContent());

        foreach (LaunchCityCode::KNOWN_CODES as $code) {
            $this->assertStringContainsString(ucfirst(strtolower($code)), $body);
        }
    }

    /**
     * The negative criterion "No hidden omission of a required MVP city",
     * proven properly.
     *
     * The test above is VACUOUS against the regression that actually
     * threatens this rule. Every one of the five launch cities has at least
     * one published cemetery in the seed, so an implementation that
     * "optimised" `launchCities()` down to *cities that have published
     * rows* would still render all five and would still pass it. That
     * optimisation looks entirely reasonable to someone who has not read
     * the negative criterion — and it is the exact failure the criterion
     * names.
     *
     * So: empty one city out and assert its filter survives anyway. A
     * launch city is a statement about where the platform operates, not a
     * summary of today's inventory; a family in Bekasi must not be told
     * the city is unserved because no operator has onboarded yet.
     *
     * Flagged by `renewal-builder` (S4-T7) as a rule that must not be
     * softened into an implementation detail when the two directory query
     * classes are merged into `app/Domain/CemeteryDirectory/`. This test is
     * what makes that non-negotiable rather than advisory.
     */
    public function test_a_launch_city_keeps_its_filter_even_with_no_published_cemeteries(): void
    {
        Cemetery::query()
            ->inCity(LaunchCityCode::BEKASI)
            ->update(['publication_status' => CemeteryPublicationStatus::UNPUBLISHED]);

        // Precondition: the city is genuinely empty now, so the only thing
        // that can put "Bekasi" on the page is the filter control itself.
        $this->assertSame(
            0,
            Cemetery::query()->published()->inCity(LaunchCityCode::BEKASI)->count(),
        );

        $body = $this->body($this->get(route('cemeteries.index'))->getContent());

        $this->assertStringContainsString('Bekasi', $body);

        // And selecting it reaches the explained §6.2 empty state — the
        // honest destination — rather than a silently absent chip.
        Livewire::test(CemeteryDirectoryIndex::class)
            ->set('city', LaunchCityCode::BEKASI)
            ->assertHasNoErrors()
            ->assertSee('Belum ada lokasi yang cocok dengan filter ini.');
    }

    /**
     * AC2 — filtering by city returns only that city's published
     * cemeteries. Asserted against real seeded data, additively (the seed
     * count is not this test's subject).
     */
    public function test_city_filter_returns_only_that_citys_published_cemeteries(): void
    {
        $expected = Cemetery::query()
            ->published()
            ->inCity(LaunchCityCode::BOGOR)
            ->pluck('name');

        $this->assertGreaterThan(0, $expected->count());

        $other = Cemetery::query()
            ->published()
            ->where('city', '!=', LaunchCityCode::BOGOR)
            ->pluck('name');

        $this->assertGreaterThan(0, $other->count());

        $rendered = Livewire::test(CemeteryDirectoryIndex::class)
            ->set('city', LaunchCityCode::BOGOR);

        foreach ($expected as $name) {
            $rendered->assertSee($name);
        }

        foreach ($other as $name) {
            $rendered->assertDontSee($name);
        }
    }

    public function test_type_filter_returns_only_that_type(): void
    {
        $tps = Cemetery::query()->published()->ofType(CemeteryType::TPS)->pluck('name');
        $tpu = Cemetery::query()->published()->ofType(CemeteryType::TPU)->pluck('name');

        $this->assertGreaterThan(0, $tps->count());
        $this->assertGreaterThan(0, $tpu->count());

        $rendered = Livewire::test(CemeteryDirectoryIndex::class)
            ->set('type', CemeteryType::TPS);

        foreach ($tps as $name) {
            $rendered->assertSee($name);
        }

        foreach ($tpu as $name) {
            $rendered->assertDontSee($name);
        }
    }

    /**
     * AC2's base guarantee. One seeded cemetery is deliberately `draft`
     * precisely so this exclusion is provable rather than vacuous.
     */
    public function test_a_draft_cemetery_never_appears_in_the_public_directory(): void
    {
        $draft = Cemetery::query()
            ->where('publication_status', CemeteryPublicationStatus::DRAFT)
            ->firstOrFail();

        $this->get(route('cemeteries.index'))->assertDontSee($draft->name);
    }

    /**
     * AC5 — the default availability presentation. `Perlu konfirmasi` must
     * be on the page, and the seeded profiles all carry the safe default,
     * so every card carries it.
     */
    public function test_indicative_availability_is_labelled_perlu_konfirmasi(): void
    {
        $this->get(route('cemeteries.index'))->assertSee('Perlu konfirmasi');
    }

    /**
     * The negative half of AC5, and the rule this whole slice exists for:
     * with every seeded cemetery on safe defaults, no success-intent badge
     * may render anywhere on the directory. Asserted against the actual
     * token the badge primitive emits for `success`, not against a label.
     */
    public function test_no_success_intent_badge_renders_while_every_cemetery_is_indicative(): void
    {
        $body = $this->body($this->get(route('cemeteries.index'))->getContent());

        $this->assertStringNotContainsString('--mk-intent-success', $body);
    }

    /**
     * AC12 + the negative criterion "No public exposure of restricted plot
     * data" — asserted against the rendered HTML, which is the surface that
     * actually matters.
     */
    public function test_restricted_capability_modes_never_reach_the_rendered_directory(): void
    {
        $body = $this->body($this->get(route('cemeteries.index'))->getContent());

        $this->assertStringNotContainsString('registry_mode', $body);
        $this->assertStringNotContainsString('certificate_mode', $body);
        $this->assertStringNotContainsString('AUTHORITATIVE', $body);
        $this->assertStringNotContainsString('PLATFORM_MANAGED', $body);
    }

    /**
     * AC3 — every card field. Checked on one known seeded cemetery so the
     * assertion names real expected values rather than "something rendered".
     */
    public function test_a_card_shows_every_ac3_field(): void
    {
        $cemetery = Cemetery::query()->published()->where('slug', 'tpu-jakarta-menteng')->firstOrFail();

        $response = $this->get(route('cemeteries.index'));

        $response->assertSee($cemetery->type);
        $response->assertSee($cemetery->name);
        $response->assertSee($cemetery->address);

        foreach ((array) $cemetery->facilities as $facility) {
            $response->assertSee((string) $facility);
        }

        // Price range WITH source — AC3 requires the attribution, not just
        // the figure. Both ends of the range are asserted:
        // `CemeteryPresenter::priceRange()` renders "{symbol} {min} -
        // {symbol} {max}", so asserting only the minimum let a regression
        // that dropped or corrupted the upper half pass on both routes.
        $response->assertSee(number_format((float) $cemetery->price_min, 0, ',', '.'));
        $response->assertSee(number_format((float) $cemetery->price_max, 0, ',', '.'));
        $response->assertSee((string) $cemetery->price_source);

        // Photo.
        $response->assertSee((string) $cemetery->primary_photo_path);
    }

    /**
     * §6.3 — an unknown `?city=` is a stated validation error, not a
     * silently empty result. The distinction matters: on a directory whose
     * negative criteria include "No hidden omission of a required MVP
     * city", a filter that quietly yields nothing is the wrong failure.
     */
    public function test_an_unknown_city_filter_states_a_validation_error_and_still_lists_cemeteries(): void
    {
        $known = Cemetery::query()->published()->firstOrFail();

        Livewire::test(CemeteryDirectoryIndex::class)
            ->set('city', 'SURABAYA')
            ->assertHasErrors('city')
            ->assertSee($known->name);
    }

    public function test_an_unknown_type_filter_states_a_validation_error(): void
    {
        Livewire::test(CemeteryDirectoryIndex::class)
            ->set('type', 'NOT_A_TYPE')
            ->assertHasErrors('type');
    }

    public function test_reset_filters_clears_both_filters(): void
    {
        Livewire::test(CemeteryDirectoryIndex::class)
            ->set('city', LaunchCityCode::BOGOR)
            ->set('type', CemeteryType::TPS)
            ->call('resetFilters')
            ->assertSet('city', '')
            ->assertSet('type', '');
    }

    /**
     * §6.5 provider unavailable, proven by dropping the real table inside
     * the test transaction — the way the FAQ tests do it, not by mocking
     * the query class. The page must state the failure and keep its
     * customer-service escape hatch, never blank or 500.
     *
     * FIXED 08 Aug 2026 — a real cross-batch integration failure, caught by
     * CI, not by either batch that wrote the two sides of it. This test
     * predates `grave_records` (S4-T7, migration `2026_08_08_100000`), which
     * added `grave_records.cemetery_id` as `restrictOnDelete` against
     * `cemeteries` — so a plain `Schema::drop('cemeteries')` now fails with
     * "cannot drop table cemeteries because other objects depend on it"
     * instead of the clean drop this test always relied on.
     * `RenewalStartTest::test_a_failed_cemetery_read_degrades_honestly_
     * instead_of_500ing` already handles this correctly (drops
     * `grave_records` first) because it was written after that migration
     * existed; this test needed the same fix once the dependency appeared
     * out from under it.
     *
     * FIXED 09 Aug 2026 — the same failure re-occurred with the `booking_drafts`
     * migration (`2026_08_08_130000`), whose FKs to `cemetery_packages`/
     * `cemeteries` block the drops on PostgreSQL (2BP01). `booking_drafts`
     * is empty in this test, so it joins the drop list below; on SQLite
     * `dropIfExists` on the empty table is a no-op.
     */
    public function test_the_page_survives_the_cemeteries_table_being_unreadable(): void
    {
        // Order-orchestration tables FK-referencing `booking_drafts` /
        // `orders` / `quotes` (Task 3/4/5, 12 Aug 2026) are dropped first so
        // PostgreSQL's `DROP TABLE` of `booking_drafts` is not blocked by the
        // incoming FKs (2BP01) — the same tripwire this comment documents.
        Schema::dropIfExists('quote_lines');
        Schema::dropIfExists('order_documents');
        Schema::dropIfExists('order_status_events');
        Schema::dropIfExists('order_parties');
        Schema::dropIfExists('deceased_profiles');
        Schema::dropIfExists('quotes');
        Schema::dropIfExists('funeral_cases');
        Schema::dropIfExists('pre_need_interests');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('booking_drafts');
        Schema::dropIfExists('grave_records');
        Schema::drop('cemetery_capability_profiles');
        Schema::drop('cemetery_packages');
        Schema::drop('cemeteries');

        Livewire::test(CemeteryDirectoryIndex::class)
            ->assertSet('directoryUnavailable', true)
            ->assertSee('Direktori lokasi sedang tidak dapat dimuat')
            ->assertSee('Hubungi Customer Service');
    }

    /**
     * §6.5, the per-cemetery half — capability resolution failing for a
     * cemetery must degrade that one card to AC4's safe defaults, not blank
     * the directory and not omit the cemetery. Proven by dropping
     * `cemetery_capability_profiles` alone, so `CemeteryPublicQuery::
     * published()` still succeeds and only the per-card resolution throws.
     * Nothing holds a foreign key into that table, so it drops cleanly on
     * PostgreSQL as well as SQLite.
     *
     * What this asserts and what it deliberately does not: the badge output
     * is IDENTICAL before and after the fix this test accompanies, because
     * `CemeteryAvailabilityIntent::forCemetery()` degrades any unrecognised
     * mode to `neutral` on purpose. So this test does not, and cannot,
     * observe the difference between the old `null` -> `''` coercion and the
     * new legal `INDICATIVE` value from the rendered HTML. What it does
     * guard is the pair staying consistent: `index.blade.php` now reads
     * `$capabilities->availabilityMode` with a plain `->`, so if the
     * component is ever reverted to assigning `null` on this path, this test
     * fatals instead of silently passing. That is the regression the typed
     * `$cards` shape declares and that no gate in this repository can check
     * for a Blade file (`phpstan.neon` sets `paths: [app]`).
     */
    public function test_a_cemetery_whose_capabilities_cannot_be_resolved_still_renders_with_safe_defaults(): void
    {
        $published = Cemetery::query()->published()->pluck('name');
        $this->assertGreaterThan(0, $published->count());

        Schema::drop('cemetery_capability_profiles');

        $rendered = Livewire::test(CemeteryDirectoryIndex::class)
            ->assertSet('directoryUnavailable', false)
            ->assertSet('capabilitiesDegraded', true);

        // Every published cemetery is still listed — a resolution failure
        // must never omit a location from the directory.
        foreach ($published as $name) {
            $rendered->assertSee($name);
        }

        // And each one carries the safe default's neutral availability
        // badge, not a blank or a success claim.
        $rendered->assertSee(CemeteryAvailabilityIntent::NEEDS_CONFIRMATION_LABEL);
        $this->assertStringNotContainsString('--mk-intent-success', $rendered->html());
    }

    /**
     * design-system.md §9.2 MUST-NOT "never convey status by colour alone",
     * and WCAG 1.4.1.
     *
     * The active and inactive filter chips share an identical structural
     * recipe and differ only in colour family, so the `icon.check` tick is
     * the ONLY non-chromatic selection cue a sighted user gets — this
     * page's `<h1>` is static and its sr-only status line is only a result
     * count, unlike the FAQ page whose heading names the active category.
     * Asserted on the rendered SVG path data rather than a class name, so
     * the test fails if the icon is removed even if the markup around it
     * survives.
     *
     * Regression guard: without this, deleting the tick as "decoration"
     * would break nothing any other test asserts.
     */
    public function test_the_active_filter_chip_carries_a_non_colour_selection_cue(): void
    {
        $checkPath = 'm4.5 12.75 6 6 9-13.5';

        // The tick that marks whichever chip is active — present on the
        // default view, where "Semua Kota"/"Semua Jenis" are selected.
        $default = $this->body($this->get(route('cemeteries.index'))->getContent());
        $this->assertStringContainsString($checkPath, $default);

        // And still present when a real city filter is the active chip.
        $filtered = Livewire::test(CemeteryDirectoryIndex::class)
            ->set('city', LaunchCityCode::BOGOR)
            ->html();

        $this->assertStringContainsString($checkPath, $filtered);
        $this->assertStringContainsString('aria-current', $filtered);
    }

    /**
     * §6.10 — the customer-service escape hatch is present on the normal
     * path too, not only on the failure paths.
     */
    public function test_the_customer_service_escape_hatch_is_present(): void
    {
        $this->get(route('cemeteries.index'))
            ->assertSee('Hubungi Customer Service')
            ->assertSee(route('bantuan.index'));
    }

    /**
     * Ordering assertions scope to the body so `<title>` cannot produce a
     * false match.
     */
    private function body(string|false $content): string
    {
        $content = (string) $content;
        $bodyStart = strpos($content, '<body');

        return $bodyStart === false ? $content : substr($content, $bodyStart);
    }
}
