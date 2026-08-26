<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Filament\Vendor\Pages\Dashboard;
use App\Filament\Vendor\Pages\EvidenceList;
use App\Filament\Vendor\Pages\PayoutStatus;
use App\Filament\Vendor\Pages\Profile;
use App\Filament\Vendor\Pages\TransactionHistory;
use App\Http\Middleware\AssignCorrelationId;
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
 * REVERTED, 26 Aug 2026 — explicit, informed owner decision, mirroring
 * `AdminPanelProvider`'s identical reversal (see its own doc block,
 * "SEVENTH change", for the full record). This panel no longer follows the
 * public site's brand identity at all:
 *
 *   - `->colors(...)` (the tokens.css-derived generated palette, shared
 *     with `/admin` via `app/Support/Design/generated/FilamentPalette.php`)
 *     is removed — this panel now uses Filament's own default colour
 *     scheme.
 *   - `->font('Inter var')` is removed — no custom font family is
 *     requested at all; Filament's own default font stack applies.
 *   - `->viteTheme(...)` is removed — that custom theme file existed
 *     solely to inject brand colours/font and carried no non-branding
 *     structural fix, so it (and its `vite.config.js` entry) were deleted
 *     outright. This panel now uses Filament's own default, package-shipped
 *     CSS build.
 *   - `->brandLogo()`/`->brandLogoHeight()` (the real raster mark,
 *     ADR-0034 Task 5, previously shared with `/admin`) are removed. In
 *     their place, `->brandName('Makam Vendor')` — plain text, not the
 *     designed wordmark/logo — is kept purely for functional
 *     identification. Judgment call, flagged in this batch's PR
 *     description for the project owner to confirm or correct.
 *
 * Dark mode is unchanged by this reversal — this panel still follows
 * Filament's default system-preference dark mode (restored by the earlier,
 * separate 26 Aug 2026 revert of PR #170, `AdminPanelProvider`'s "SIXTH
 * change"). `ReportContentSecurityPolicy`'s `https://fonts.bunny.net`
 * allowance is also left untouched by this batch (now simply unused, not
 * actively wrong) — CSP configuration is out of this batch's scope; see
 * `AdminPanelProvider`'s doc block for the same note.
 */
final class VendorPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('vendor')
            ->path('vendor')
            ->login()
            // See this class's own doc block above. Plain text only, kept
            // for functional identification.
            ->brandName('Makam Vendor')
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
            ]);
    }
}
