<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Filament\Vendor\Pages\Dashboard;
use App\Filament\Vendor\Pages\EvidenceList;
use App\Filament\Vendor\Pages\PayoutStatus;
use App\Filament\Vendor\Pages\Profile;
use App\Filament\Vendor\Pages\TransactionHistory;
use App\Http\Middleware\AssignCorrelationId;
use Filament\FontProviders\LocalFontProvider;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
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
 * `/vendor` panel provider — `information-architecture.md` §5.
 *
 * Panel entry is decided by
 * `App\Platform\IdentityAccess\Panel\VendorPanelAccessPolicy`, reached through
 * `User::canAccessPanel()`'s `'vendor'` arm. Filament's `Authenticate`
 * middleware below is what calls it. Record visibility is a separate, later
 * decision made per query by `App\Filament\Vendor\Concerns\ScopesToCurrentVendor`
 * — see AC4's "SHALL NOT grant record access on panel membership alone".
 *
 * `->colors()` loads the same tokens.css-derived palette as
 * `AdminPanelProvider`, via the same generator output, so the two panels
 * cannot drift apart on status colour (design-system.md §3.7 requires the
 * public site and the panels to render the same status colours). The previous
 * version of this file passed `fn () => []`, which silently fell back to
 * Filament's own default palette and defeated that requirement.
 *
 * `->brandLogo()` / `->brandLogoHeight()` (brand-identity-adoption Task 5,
 * ADR-0034) wire the same real raster mark as `/admin`
 * (`public/brand/mark-96.png`, Task 3), so the two panels cannot drift on
 * brand mark either.
 */
final class VendorPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('vendor')
            ->path('vendor')
            ->login()
            ->colors($this->filamentColors())
            // design-system.md §7.1/OQ-07: dark mode is explicitly OUT of
            // MVP scope — see AdminPanelProvider's identical fix for the
            // full explanation. Filament's `defaultThemeMode(System)`
            // default means this panel silently rendered dark on a
            // dark-preference browser too, with the same unpaired
            // `text-neutral-*` contrast problem.
            ->darkMode(false)
            // ADR-0034: official mark; stacked lockup reads badly at 2rem, so
            // the panel carries the mark — a horizontal lockup is OQ-12 scope.
            ->brandLogo(asset('brand/mark-96.png'))
            ->brandLogoHeight('2rem')
            ->discoverResources(
                in: app_path('Filament/Vendor/Resources'),
                for: 'App\\Filament\\Vendor\\Resources',
            )
            ->pages([
                Dashboard::class,
                TransactionHistory::class,
                PayoutStatus::class,
                EvidenceList::class,
                Profile::class,
            ])
            ->widgets([
                Widgets\AccountWidget::class,
            ])
            ->middleware([
                // Same reasoning as AdminPanelProvider's own copy: this panel
                // declares an explicit middleware array rather than going
                // through bootstrap/app.php's `web` group, so it needs its own
                // correlation-id origin point, first, before anything that
                // might want to reference one.
                AssignCorrelationId::class,
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
            // Self-hosted only, and the same theme file as /admin —
            // `provider: LocalFontProvider::class` is REQUIRED here for the
            // same reason as `AdminPanelProvider`'s identical fix: see its
            // class-level doc-block note (SEC-08 CSP-enforcement follow-up)
            // for why omitting it silently falls back to Filament's
            // BunnyFontProvider once a custom family is set.
            ->font('Inter var', provider: LocalFontProvider::class)
            ->viteTheme('resources/css/filament/admin/theme.css');
    }

    /**
     * The tokens.css-derived palette, shared with `/admin`. Throws rather than
     * falling back to Filament's defaults if the generator has never run — see
     * `AdminPanelProvider::filamentColors()`, which this mirrors deliberately.
     *
     * @return array<string, array<int, string>|string>
     */
    private function filamentColors(): array
    {
        $path = app_path('Support/Design/generated/FilamentPalette.php');

        if (! is_file($path)) {
            throw new RuntimeException(
                'Filament palette has not been generated. Run `php artisan design:generate-filament-palette` '
                ."(expected at {$path})."
            );
        }

        /** @var array<string, array<int, string>|string> $palette */
        $palette = require $path;

        return $palette;
    }
}
