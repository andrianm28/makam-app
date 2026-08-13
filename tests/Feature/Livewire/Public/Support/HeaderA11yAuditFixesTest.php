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

    public function test_the_footer_link_contrast_rule_is_present_in_the_compiled_stylesheet(): void
    {
        // The hermetic suite runs without Vite, so assert against the
        // stylesheet source (app.css) where the scoped rule lives — the
        // compiled build is verified by the CI frontend job and by the
        // browser/a11y smoke test against the deployed app.
        $css = file_get_contents(
            resource_path('css/app.css'),
        );

        $this->assertStringContainsString(
            'footer a {',
            $css,
            'The footer-scoped link rule must exist to override the global link colour.',
        );

        $this->assertStringContainsString(
            '--mk-text-inverse',
            $css,
            'The footer link colour must use the inverse-text token (white on primary-900).',
        );
    }
}
