<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Filament\Admin\Pages\FeatureGateAdmin;
use App\Filament\Admin\Pages\InAppNotifications;
use App\Filament\Admin\Pages\PasswordReauthentication;
use App\Filament\Admin\Pages\Reports;
use App\Filament\Admin\Widgets\FailedPaymentExceptionQueueWidget;
use App\Filament\Admin\Widgets\FinancialOverviewWidget;
use App\Filament\Admin\Widgets\OrderStatusOverviewWidget;
use App\Filament\Admin\Widgets\PlatformOverviewWidget;
use App\Http\Middleware\AssignCorrelationId;
use Filament\FontProviders\LocalFontProvider;
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
 * ONE deliberate deviation from the §8.3 snippet, ORIGINALLY written as a
 * guess and now CORRECTED against the real installed package (SEC-08 CSP-
 * enforcement follow-up, 25 Aug 2026): this batch first assumed
 * `->font('Inter var')` with no `provider:` argument would self-host, on
 * the theory that Filament only needed the family name and theme.css's own
 * `@font-face` would supply the rest. That assumption was wrong.
 * `vendor/filament/filament/src/Panel/Concerns/HasFont.php`'s
 * `getFontProvider()` resolves to `BunnyFontProvider::class` — not
 * `LocalFontProvider::class` — the moment a CUSTOM family is set (i.e.
 * `hasCustomFontFamily()` is true) and no `provider:` argument overrides
 * it; `LocalFontProvider` is only the default when the family itself is
 * left null. So this panel was silently emitting a
 * `<link href="https://fonts.bunny.net/css?family=inter-var...">` tag on
 * every request — invisible under report-only, blocked outright once SEC-08
 * flipped this app to enforcing CSP (`style-src` has no third-party origin,
 * by design — see `ReportContentSecurityPolicy`'s own doc block). The fix
 * is exactly the `provider: LocalFontProvider::class` argument this note
 * previously talked itself out of: confirmed against the real installed
 * `filament/filament` v5.7.3, `LocalFontProvider::getHtml()` returns empty
 * HTML whenever no `$url` is passed (`vendor/filament/filament/src/
 * FontProviders/LocalFontProvider.php`), so passing it here emits NO
 * stylesheet link at all and relies entirely on theme.css's own
 * `@font-face`, exactly as originally intended — self-hosted, no CDN
 * request, no visitor-IP leak to a third party on a page that may be
 * handling private case/order data.
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
 * A SECOND deliberate omission, RESOLVED by the batch that added the first
 * Admin Resource (`App\Filament\Admin\Resources\FaqArticles\
 * FaqArticleResource`, S4-T2): the documented convention also wires
 * `->discoverResources(in: app_path('Filament/Admin/Resources'), for:
 * ...)`. Until that batch, this repo's scaffold held only a flat
 * `app/Filament/Admin/.gitkeep` — no `Resources/` directory existed for a
 * discover*() call to scan, and Filament's discovery scanner's tolerance
 * for a missing directory was unconfirmed, so the panel registered only
 * the package's own built-in Dashboard/Account/Info widgets statically and
 * omitted every discover*() call. The `->discoverResources()` call below
 * was added back in once a real `Resources/` directory existed to point it
 * at — verified against the real installed `filament/filament` v5.7.3
 * (`Filament\Panel\Concerns\HasComponents::discoverResources(string $in,
 * string $for): static`) rather than assumed. No matching
 * `discoverPages()`/`discoverWidgets()` call was added: this batch did not
 * populate `Filament/Admin/Pages/` or `Filament/Admin/Widgets/`, and adding
 * a discovery call for a directory nothing populates would risk the same
 * unconfirmed-missing-directory concern this paragraph originally raised.
 *
 * A THIRD change, made after the first Admin Resource's own test suite hit
 * a real CI failure: `->default()` (`Filament\Panel::default(bool|Closure
 * $condition = true): static`, confirmed against the same installed
 * `filament/filament` v5.7.3 — `$isDefault` defaults to `false`). This
 * app has exactly one panel, but `Filament\PanelRegistry::getDefaultPanel()`
 * throws `NoDefaultPanelSetException` whenever something needs "the
 * current panel" without a URL-based context to infer it from — which is
 * exactly what `Livewire::test(SomeFilamentPage::class)` does (it invokes
 * the component directly, bypassing the `/admin`-prefixed HTTP request
 * that normally establishes panel context via route middleware). Real
 * `$this->get('/admin/...')`-based tests were unaffected, since routing
 * always supplies that context regardless of `isDefault()`. Marking the
 * one real panel as the default is the standard fix for a single-panel
 * app and has no effect on which panel serves a `/admin`-prefixed
 * request, which was already unambiguous.
 *
 * A FOURTH change (brand-identity-adoption Task 5, ADR-0034): `->brandLogo()`
 * / `->brandLogoHeight()` wire the real raster mark
 * (`public/brand/mark-96.png`, Task 3) into the panel header, replacing
 * Filament's default text/icon brand. Confirmed against the installed
 * `filament/filament` v5.7.3 — both methods live on
 * `Filament\Panel\Concerns\HasBrandLogo`, matching the names this batch's
 * brief assumed; no correction needed.
 *
 * A FIFTH change (ADM-001, `.kiro/specs/admin-operations/requirements.md`
 * AC1/AC11): `Filament/Admin/Widgets/` is no longer the unpopulated
 * directory the SECOND note above describes. Four dashboard-summary widgets
 * now exist there and are registered explicitly in `->widgets([...])`
 * below, the same explicit-array shape `->pages([...])` already uses — not
 * `->discoverWidgets()`, for the identical unconfirmed-discovery-behaviour
 * reason the SECOND note gives for pages. See each widget class's own doc
 * block for what it covers and, for the two finance-gated ones, why they
 * are authorized and query-scoped differently from the master-data ones.
 */
class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('admin')
            ->path('admin')
            ->default()
            ->login()
            ->colors($this->filamentColors())
            // design-system.md §7.1/OQ-07: dark mode is explicitly OUT of
            // MVP scope ("No dark mode until OQ-07 is resolved" / "Add
            // `dark:` utilities" is listed as a ❌ anti-pattern) — it has no
            // required states in screen-inventory.md and no test coverage.
            // Filament defaults `hasDarkMode(true)` with
            // `defaultThemeMode(ThemeMode::System)`, so on a browser/OS with
            // a dark colour-scheme preference the panel silently rendered
            // dark: every custom admin Blade view under
            // resources/views/filament/admin/ (feature-gate-admin,
            // finance-reports, outgoing-payments-report, etc.) only defines
            // light-oriented `text-neutral-700/800/900`, with no `dark:`
            // pairing anywhere (none may exist while OQ-07 stays open), so
            // that text rendered as dark-gray-on-near-black — confirmed via
            // a live screenshot of Gerbang Fitur's "ID"/"Kapabilitas"
            // columns during this session's UAT pass. `->darkMode(false)`
            // is Filament's own documented way to force light theme
            // unconditionally (Panel/Concerns/HasDarkMode.php) and matches
            // the design system's stance without adding a single `dark:`
            // class to any Blade view — the actual gap is "this panel
            // should never enter dark mode at all," not "every view is
            // missing its dark-mode pairing."
            ->darkMode(false)
            // ADR-0034: official mark; stacked lockup reads badly at 2rem, so
            // the panel carries the mark — a horizontal lockup is OQ-12 scope.
            ->brandLogo(asset('brand/mark-96.png'))
            ->brandLogoHeight('2rem')
            ->discoverResources(
                in: app_path('Filament/Admin/Resources'),
                for: 'App\\Filament\\Admin\\Resources',
            )
            ->pages([
                Pages\Dashboard::class,
                FeatureGateAdmin::class,
                Reports::class,
                PasswordReauthentication::class,
                InAppNotifications::class,
            ])
            ->widgets([
                Widgets\AccountWidget::class,
                Widgets\FilamentInfoWidget::class,
                // ADM-001 (AC1, AC11 partial) — dashboard summary. Order
                // matters here: it is render order on the default Dashboard
                // page. Master-data counts first (visible to all four
                // back-office roles), then the narrower finance-gated
                // widgets, which simply do not render for an actor without
                // ledger-read authority (`canView()` returns false).
                PlatformOverviewWidget::class,
                OrderStatusOverviewWidget::class,
                FinancialOverviewWidget::class,
                FailedPaymentExceptionQueueWidget::class,
            ])
            ->middleware([
                // S3-T10 (platform-audit AC10 / platform-outbox AC13): this
                // panel does not go through bootstrap/app.php's `web`
                // middleware group at all (it declares this explicit array
                // instead), so it needs its own copy of the correlation-id
                // origin point. Placed first, before session/auth
                // middleware, so a correlation id already exists for
                // anything later in the stack that might want to reference
                // it (e.g. AuthenticateSession's session-recording path) —
                // /admin is the primary surface where privileged mutations,
                // and therefore Audit::record() calls, will happen.
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
            // Self-hosted only — design-system.md §1.4: no CDN/Google Fonts
            // request. Staging is noindex/access-restricted and a
            // third-party font fetch leaks a visitor's IP on a page that
            // may be handling private case/order data. `provider:
            // LocalFontProvider::class` is REQUIRED here, not optional —
            // see the class-level doc-block note above (SEC-08 CSP-
            // enforcement follow-up) for why omitting it silently falls
            // back to Filament's BunnyFontProvider once a custom family is
            // set, confirmed against the real installed package.
            ->font('Inter var', provider: LocalFontProvider::class)
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
