<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Public\Support;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression tests for the browser/a11y audit findings (13 Aug 2026):
 *
 * 1. `landmark-unique` — the desktop nav and the mobile nav panel both used
 *    `aria-label="Menu utama"`, so axe flagged two landmarks sharing one
 *    accessible name. The mobile panel is now labelled "Menu utama (seluler)"
 *    — distinct accessible name, identical visible labels (IA §2 unchanged).
 * 2. `color-contrast` — footer links were painted by the global
 *    `a { color: var(--mk-text-link) }` (primary-600, 1.68:1 against the
 *    primary-900 footer). A footer-scoped `footer a` rule now uses
 *    --mk-text-inverse (white), and the built CSS is asserted to carry it.
 *    This test can't run a live axe scan (hermetic suite), so it pins the
 *    two source-level invariants the fixes rely on: distinct accessible
 *    names, and the footer link rule present in the compiled stylesheet.
 *
 *    UPDATED 24 Sep 2026 (pixel-fidelity 1:1 visual clone of FFI) — the
 *    footer-scoped override above is REMOVED, not kept. The footer is no
 *    longer bg-primary-900; it's a light bg-neutral-100 surface
 *    (layouts/app.blade.php), so the scoped `footer a { color:
 *    --mk-text-inverse }` rule this test used to pin became the bug: white
 *    text at 1.18:1 on light gray, caught by the real Playwright axe-core
 *    smoke test. The invariant this test now pins is the opposite one —
 *    that no such override exists, so footer links correctly fall through
 *    to the global `a`/`a:hover` rules like every other link on the page.
 */
final class HeaderA11yAuditFixesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_the_mobile_nav_and_desktop_nav_have_distinct_accessible_names(): void
    {
        $html = $this->get('/privasi')->assertOk()->getContent();

        $this->assertSame(
            1,
            substr_count($html, 'aria-label="Menu utama"'),
            'The desktop nav must keep the canonical "Menu utama" accessible name exactly once.',
        );

        $this->assertSame(
            1,
            substr_count($html, 'aria-label="Menu utama (seluler)"'),
            'The mobile nav panel must carry the distinct "Menu utama (seluler)" accessible name.',
        );
    }

    public function test_the_mobile_nav_panel_starts_hidden_and_is_control_related(): void
    {
        $html = $this->get('/privasi')->assertOk()->getContent();

        $this->assertStringContainsString(
            'aria-expanded="false"',
            $html,
            'The hamburger button must start with aria-expanded="false".',
        );

        $this->assertMatchesRegularExpression(
            '/aria-controls="([^"]+-mobile-menu)"/',
            $html,
            'The hamburger button must reference its panel via aria-controls.',
        );

        $this->assertMatchesRegularExpression(
            '/<nav id="[^"]+-mobile-menu"[^>]*class="hidden/',
            $html,
            'The mobile nav panel must start with the hidden class.',
        );
    }

    public function test_the_footer_has_no_stale_inverse_link_override(): void
    {
        // The hermetic suite runs without Vite, so assert against the
        // stylesheet source (app.css) — the compiled build is verified by
        // the CI frontend job and by the browser/a11y smoke test against
        // the deployed app.
        //
        // The footer is now a light bg-neutral-100 surface (pixel-fidelity
        // 1:1 clone of FFI, 24 Sep 2026), not the bg-primary-900 surface
        // this test used to assert a `footer a { color: --mk-text-inverse }`
        // override for. That override is now the bug (white text at 1.18:1
        // on light gray), so this test pins its absence instead.
        $css = file_get_contents(
            resource_path('css/app.css'),
        );

        $this->assertStringNotContainsString(
            'footer a {',
            $css,
            'The footer no longer needs a scoped link-colour override — it should fall through to the global `a` rule.',
        );

        $this->assertStringContainsString(
            'color: var(--mk-text-link);',
            $css,
            'Footer links rely on the global link-colour rule now that the footer is a light surface.',
        );
    }
}
