<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use Filament\Facades\Filament;
use Tests\TestCase;

/**
 * design-system.md §7.1/OQ-07: dark mode is explicitly OUT of MVP scope
 * ("No dark mode until OQ-07 is resolved") and adding `dark:` Tailwind
 * utilities is listed as a ❌ anti-pattern. Filament defaults
 * `hasDarkMode(true)` with `defaultThemeMode(ThemeMode::System)`, so on a
 * browser/OS reporting a dark colour-scheme preference both panels
 * silently rendered dark — every custom admin Blade view under
 * `resources/views/filament/admin/` only defines light-oriented
 * `text-neutral-700/800/900` with no `dark:` pairing (none may exist while
 * OQ-07 stays open), so that text rendered as dark-gray-on-near-black.
 * Found via a live screenshot of the Gerbang Fitur (Feature Gates) page
 * during a UAT pass against the deployed beta site.
 *
 * `AdminPanelProvider`/`VendorPanelProvider` now both call
 * `->darkMode(false)` immediately after `->colors($this->filamentColors())`
 * — Filament's own documented way to force light theme unconditionally
 * (`Filament\Panel\Concerns\HasDarkMode`) — over adding `dark:` classes to
 * every affected Blade view, which the design system forbids outright.
 * This proves both panels actually carry that setting, over the real panel
 * registry rather than by re-reading the provider source, mirroring
 * `PanelBrandingTest`'s pattern for the same two panels.
 *
 * ---------------------------------------------------------------------------
 * VERIFICATION STATUS
 * ---------------------------------------------------------------------------
 * Same host constraint as this repo's other Filament panel tests
 * (`PanelBrandingTest`, `AdminPanelHttpAccessTest` et al.): PHP CLI here is
 * below Filament v5.7.3's floor, so `php artisan`/PHPUnit cannot execute on
 * this host. `php -l` only. Written to run for real in CI, which installs a
 * PHP version this pin supports.
 */
final class PanelDarkModeDisabledTest extends TestCase
{
    public function test_panels_have_dark_mode_disabled(): void
    {
        foreach (['admin', 'vendor'] as $id) {
            $panel = Filament::getPanel($id);

            $this->assertFalse($panel->hasDarkMode(), "{$id} panel must not offer dark mode (design-system.md OQ-07)");
        }
    }
}
