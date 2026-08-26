<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use Filament\Facades\Filament;
use Tests\TestCase;

/**
 * REWRITTEN, 26 Aug 2026 — explicit, informed owner decision (see
 * `AdminPanelProvider`'s doc block, "SEVENTH change", for the full record).
 * admin/vendor Filament panels no longer follow the public site's Earth
 * brown / Leaf green brand identity: `->colors(...)` (the tokens.css-derived
 * generated palette), `->font(...)`, `->viteTheme(...)`, and
 * `->brandLogo()`/`->brandLogoHeight()` (the real raster mark, previously
 * proven by this test's earlier version,
 * `test_panels_carry_the_brand_mark_and_generated_palette`) were all
 * removed from both panel providers. Both panels now render with
 * Filament's own stock, out-of-the-box appearance.
 *
 * What THIS test proves instead: both panels carry only a plain-text
 * `->brandName(...)` (functional identification, not the designed
 * wordmark/logo — a judgment call flagged in this batch's PR description)
 * and carry NO brand logo at all — `getBrandLogo()` must be null, over the
 * real panel registry rather than by re-reading the provider source.
 *
 * `->brandName()`/`getBrandName()` live on `Filament\Panel\Concerns\HasBrandName`
 * (stable Filament API surface, same trait family as the `HasBrandLogo`
 * this test previously exercised).
 *
 * ---------------------------------------------------------------------------
 * VERIFICATION STATUS
 * ---------------------------------------------------------------------------
 * Same host constraint as this repo's other Filament panel tests
 * (`AdminPanelHttpAccessTest` et al.): PHP CLI here is below Filament
 * v5.7.3's floor, so `php artisan`/PHPUnit cannot execute on this host.
 * `php -l` only. Written to run for real in CI, which installs a PHP
 * version this pin supports.
 */
final class PanelBrandingTest extends TestCase
{
    public function test_panels_carry_only_a_plain_text_brand_name_and_no_logo(): void
    {
        $expected = [
            'admin' => 'Makam Admin',
            'vendor' => 'Makam Vendor',
        ];

        foreach ($expected as $id => $name) {
            $panel = Filament::getPanel($id);

            $this->assertSame($name, $panel->getBrandName(), "{$id} panel must carry a plain-text brand name for functional identification.");
            $this->assertNull($panel->getBrandLogo(), "{$id} panel must not carry a brand logo — admin/vendor panels use Filament's stock appearance.");
        }
    }
}
