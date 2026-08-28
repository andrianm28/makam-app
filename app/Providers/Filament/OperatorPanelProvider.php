<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Filament\Operator\Pages\Dashboard;
use App\Filament\Operator\Pages\PlotFloorMap;
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
 * `/operator` panel provider — the TPU/TPS operator dashboard roadmap's
 * Phase A skeleton (`/home/ubuntu/.claude/plans/swirling-cooking-umbrella.md`).
 *
 * Panel entry is decided by
 * `App\Platform\IdentityAccess\Panel\CemeteryOperatorPanelAccessPolicy`,
 * reached through `User::canAccessPanel()`'s `'operator'` arm. Filament's
 * `Authenticate` middleware below is what calls it. Record visibility is a
 * separate, later decision made per query by
 * `App\Filament\Operator\Concerns\ScopesToCurrentCemetery` — see AC4's
 * "SHALL NOT grant record access on panel membership alone".
 *
 * Follows `VendorPanelProvider`'s current (26 Aug 2026 reversal) shape: no
 * brand-token customisation. `docs/design/design-system.md` §8.3 records
 * that `/admin`/`/vendor` panels no longer follow the public site's brand
 * identity at all — this panel is built the same way from the start, so
 * there is no later reversal to make. Stock Filament colour scheme, stock
 * font stack, `->brandName()` only for functional identification.
 *
 * Ships the placeholder Dashboard plus, from Phase D (28 Aug 2026), the
 * cemetery-scoped `PlotFloorMap` page. No Resources yet — Phase C's
 * `CemeteryOrderResource` is the first. Pages are listed explicitly rather
 * than discovered, for the same unconfirmed-discovery-behaviour reason
 * `AdminPanelProvider`'s doc block records.
 */
final class OperatorPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('operator')
            ->path('operator')
            ->login()
            ->brandName('Makam Operator')
            ->pages([
                Dashboard::class,
                PlotFloorMap::class,
            ])
            ->widgets([
                Widgets\AccountWidget::class,
            ])
            ->middleware([
                // Same reasoning as VendorPanelProvider's own copy: this
                // panel declares an explicit middleware array rather than
                // going through bootstrap/app.php's `web` group, so it
                // needs its own correlation-id origin point, first, before
                // anything that might want to reference one.
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
