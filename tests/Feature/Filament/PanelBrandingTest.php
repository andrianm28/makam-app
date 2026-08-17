<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use Filament\Facades\Filament;
use Illuminate\Contracts\Support\Htmlable;
use Tests\TestCase;

/**
 * brand-identity-adoption Task 5 (ADR-0034/OQ-09/OQ-12).
 *
 * `AdminPanelProvider` and `VendorPanelProvider` both call
 * `->brandLogo(asset('brand/mark-96.png'))->brandLogoHeight('2rem')`
 * immediately after `->colors($this->filamentColors())`, replacing
 * Filament's default text/icon brand with the real raster mark produced by
 * Task 3's asset pipeline. This test proves both panels actually carry it,
 * over the real panel registry rather than by re-reading the provider
 * source.
 *
 * `->brandLogo()` / `->brandLogoHeight()` and their `get*()` readers
 * (`getBrandLogo()`, `getBrandLogoHeight()`) live on
 * `Filament\Panel\Concerns\HasBrandLogo`, confirmed against the installed
 * `vendor/filament/filament` v5.7.3 (this repo's composer.lock pin) —
 * `grep -rn "function brandLogo" vendor/filament/` and the paired
 * `brandLogoHeight|getBrandLogo` grep both resolved to that one trait, no
 * differently-named API to correct for.
 *
 * ---------------------------------------------------------------------------
 * VERIFICATION STATUS
 * ---------------------------------------------------------------------------
 * Same host constraint as this repo's other Filament panel tests
 * (`AdminPanelHttpAccessTest` et al.): PHP CLI here is 8.3.6, below
 * Filament v5.7.3's floor, so `php artisan`/PHPUnit cannot execute on this
 * host. `php -l` only. Written to run for real in CI, which installs a
 * PHP version this pin supports.
 */
final class PanelBrandingTest extends TestCase
{
    public function test_panels_carry_the_brand_mark_and_generated_palette(): void
    {
        foreach (['admin', 'vendor'] as $id) {
            $panel = Filament::getPanel($id);
            $logo = $panel->getBrandLogo();
            $this->assertNotNull($logo, "{$id} panel has no brand logo");
            $this->assertStringContainsString(
                'brand/mark-96.png',
                (string) ($logo instanceof Htmlable ? $logo->toHtml() : $logo)
            );
            $this->assertSame('2rem', $panel->getBrandLogoHeight());
        }
    }
}
