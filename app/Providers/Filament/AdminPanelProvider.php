<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Widgets;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use RuntimeException;

/**
 * `/admin` panel provider — design-system.md §8.3.
 *
 * ---------------------------------------------------------------------------
 * VERIFICATION STATUS — read before trusting this file
 * ---------------------------------------------------------------------------
 * design-system.md §8.3 itself flags this section as "least-verified":
 * the theme-file path, the `vendor/filament/.../theme.css` import target,
 * and the `LocalFontProvider` FQCN were "written from the documented
 * Filament 5 baseline and are not verified against an installed Filament
 * 5." This batch does NOT resolve that flag. `composer install` cannot be
 * run on this host (per this repo's CLAUDE.md — builds happen in CI only),
 * `vendor/` is empty, and there is no installed Filament 5 to check this
 * class's API surface against. Everything below is written against
 * Filament's documented v3-through-v5 PanelProvider shape (middleware
 * list, discover*() calls, ->colors()/->font()/->viteTheme() signatures),
 * which has been stable across recent major versions in public
 * documentation, but that is NOT the same as verifying it compiles or
 * boots against filament/filament v5.7.3 (the version this repo's
 * composer.lock pins). Confirm against the real package at scaffold time,
 * as design-system.md §8.3 already says to.
 *
 * What THIS batch does resolve: OQ-09. `->colors()` below loads the
 * GENERATED palette (app/Support/Design/generated/FilamentPalette.php,
 * produced by `php artisan design:generate-filament-palette` from
 * resources/css/tokens.css) instead of the hand-copied hex array
 * design-system.md's §8.3 snippet showed as a known, called-out gap.
 *
 * ONE deliberate deviation from the §8.3 snippet, called out rather than
 * silently guessed: the snippet's `->font('Inter var',
 * provider: LocalFontProvider::class)` names a class with no `use`
 * statement and no confirmed namespace anywhere in this codebase or in
 * Filament's stable public API surface as far as this batch could
 * determine without an installed package to check against — i.e. specific
 * reason to doubt it, not just the section's general uncertainty flag.
 * Rather than write a `use` statement for a FQCN that might not resolve,
 * this file self-hosts the font the same way resources/css/app.css already
 * does for the public site (design-system.md §8.2's `@font-face` block):
 * theme.css below declares the `@font-face`, and `->font('Inter var')`
 * only needs to name the family Filament should apply — no provider class
 * required. If a real Filament 5 install's `LocalFontProvider` (or
 * equivalent) turns out to be the more idiomatic path, that is a one-line
 * change once verified; this repo has no path to verify it today.
 *
 * Status badges in Admin Resources should resolve colour/icon/label
 * through `App\Support\Design\StatusIntent` (design-system.md §3.7), e.g.:
 *
 *   Tables\Columns\TextColumn::make('status')
 *       ->badge()
 *       ->color(fn (string $state): string => StatusIntent::filamentColor($state))
 *       ->icon(fn (string $state): string => StatusIntent::icon($state))
 *       ->formatStateUsing(fn (string $state): string => StatusIntent::label($state));
 *
 * No Admin Resource exists yet in this repo (app/Filament/Admin/ is an
 * empty scaffold directory) and Resources are not this batch's file scope,
 * so that usage is documented here rather than implemented anywhere.
 *
 * A SECOND deliberate omission: the documented convention also wires
 * `->discoverResources(in: app_path('Filament/Admin/Resources'), ...)`
 * (and the equivalent for Pages/Widgets). This repo's scaffold only has a
 * flat `app/Filament/Admin/.gitkeep` — the `Resources/`, `Pages/`, and
 * `Widgets/` subdirectories a discover*() call would scan don't exist yet,
 * and creating them is outside this file's ownership (app/Filament/Admin/**
 * is a shared scaffold area for a future Resource-building batch, not this
 * one). Filament's discovery scanner is UNCONFIRMED to tolerate a missing
 * directory gracefully, so rather than risk a boot-time exception from a
 * path that doesn't exist, this panel registers only the package's own
 * built-in Dashboard/Account/Info widgets statically below and omits every
 * discover*() call. Whoever adds the first Admin Resource should add the
 * matching discoverResources() call back in at that point.
 */
class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('admin')
            ->path('admin')
            ->login()
            ->colors($this->filamentColors())
            ->pages([
                Pages\Dashboard::class,
            ])
            ->widgets([
                Widgets\AccountWidget::class,
                Widgets\FilamentInfoWidget::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ])
            // Self-hosted only — design-system.md §1.4: no CDN/Google Fonts
            // request. Staging is noindex/access-restricted and a
            // third-party font fetch leaks a visitor's IP on a page that
            // may be handling private case/order data. No `provider:` here
            // — the `@font-face` lives in theme.css (loaded via
            // ->viteTheme() below), so Filament only needs the family name.
            // See the class-level VERIFICATION STATUS note above for why
            // this deviates from design-system.md §8.3's
            // `provider: LocalFontProvider::class` snippet.
            ->font('Inter var')
            ->viteTheme('resources/css/filament/admin/theme.css');
    }

    /**
     * Loads the tokens.css-derived palette instead of a hand-maintained hex
     * array (OQ-09, design-system.md §8.3/§9.1). Throws loudly if the
     * generator has never been run — a missing palette should fail panel
     * boot, not silently fall back to Filament's own default colours,
     * which would defeat the entire point of §3.7 (public site and admin
     * panel must render the same status colours).
     *
     * @return array<string, array<int, string>|string>
     */
    private function filamentColors(): array
    {
        $path = app_path('Support/Design/generated/FilamentPalette.php');

        if (! is_file($path)) {
            throw new RuntimeException(
                'Filament palette has not been generated. Run: php artisan design:generate-filament-palette'
            );
        }

        /** @var array<string, array<int, string>|string> $palette */
        $palette = require $path;

        return $palette;
    }
}
