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
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\View\PanelsRenderHook;
use Filament\Widgets;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Vite;
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
 * A SIXTH change (26 Aug 2026) REVERTS the SECOND deviation note above and
 * PR #174's font-provider fix, both as an explicit, informed decision by
 * the project owner, not a correction of an error in either prior fix:
 *
 *   1. Dark mode: `->darkMode(false)` (added by PR #170, efb8493,
 *      "fix(filament): disable dark mode on admin and vendor panels") is
 *      removed — see the inline comment at the `->colors()` call site
 *      above for the full record and the accepted risk being knowingly
 *      reaccepted (recurrence of the dark-mode legibility bug PR #170
 *      fixed, since OQ-07 stays open and no Blade view has `dark:`
 *      pairing). This panel again follows Filament's own default
 *      (`hasDarkMode(true)`, system-preference theme).
 *   2. Font provider: `provider: LocalFontProvider::class` (added by PR
 *      #174's CSP fix, 5aca419) is removed from the `->font()` call below
 *      — see that call site's own inline comment for the full record.
 *      Filament's documented default (`BunnyFontProvider` for a custom
 *      family) is restored, which re-opens `ReportContentSecurityPolicy`
 *      to `https://fonts.bunny.net` on `style-src`/`style-src-elem` and
 *      `font-src` — see that middleware's own doc block for the exact
 *      origins and why both are needed, confirmed against the real
 *      installed `filament/filament` `BunnyFontProvider::getHtml()` source
 *      and a real fetch of Bunny's CSS response.
 *
 * Neither reversal is a "fix" of anything — both known risks (dark-mode
 * legibility, third-party font origin) are knowingly reaccepted, not
 * resolved. `PanelDarkModeDisabledTest`/`ReportContentSecurityPolicyTest`
 * were updated to match; no `dark:` Blade classes were added anywhere as
 * part of this change.
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
            // REVERTED, 26 Aug 2026 — explicit, informed owner decision, not
            // a correction of a mistake. See this class's own doc block
            // ("SIXTH change" note below) for the full record: PR #170
            // (efb8493, "fix(filament): disable dark mode on admin and
            // vendor panels") added `->darkMode(false)` here to force light
            // theme unconditionally, because OQ-07 (design-system.md §7.1)
            // is open and no custom admin Blade view under
            // resources/views/filament/admin/ pairs its light-oriented
            // `text-neutral-700/800/900` classes with a `dark:` variant.
            // That call is intentionally removed: Filament's own default
            // (`hasDarkMode(true)`, `defaultThemeMode(ThemeMode::System)`)
            // is restored, so this panel once again follows the visitor's
            // browser/OS colour-scheme preference. KNOWN, ACCEPTED RISK: on
            // a dark-preference browser/OS, the legibility bug PR #170 fixed
            // (dark-gray-on-near-black text, confirmed via a live screenshot
            // of Gerbang Fitur's "ID"/"Kapabilitas" columns) can recur —
            // OQ-07 is still unresolved and no `dark:` pairing exists on any
            // affected view. This is a knowing reacceptance of that risk,
            // not a claim the underlying gap is fixed; do not add `dark:`
            // classes as part of this revert — that would be new,
            // out-of-scope work.
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
            // REVERTED, 26 Aug 2026 — explicit, informed owner decision.
            // See this class's own doc block ("SIXTH change" note below)
            // for the full record: PR #174's CSP fix (5aca419) added
            // `provider: LocalFontProvider::class` here because Filament's
            // default `BunnyFontProvider` (which `getFontProvider()`
            // resolves to for any custom font family with no explicit
            // `provider:` override) emits a `<link>` to
            // `https://fonts.bunny.net`, and that origin was not allowed by
            // `ReportContentSecurityPolicy` — blocked outright once SEC-08
            // flipped CSP to enforcing. That `provider:` argument is
            // intentionally removed here: Filament's documented default
            // (`BunnyFontProvider` for a custom family with no override) is
            // restored. `ReportContentSecurityPolicy` now allows
            // `https://fonts.bunny.net` on `style-src-elem`/`style-src` (for
            // the stylesheet `<link>`) and `font-src` (for the actual font
            // files, confirmed against the real Bunny CSS response) — see
            // that middleware's own doc block for the exact origins and the
            // security trade-off this reopens. Do not reintroduce
            // `LocalFontProvider::class` here without also re-closing that
            // CSP exception; the two must move together.
            ->font('Inter var')
            ->viteTheme('resources/css/filament/admin/theme.css')
            // UI-audit fix (26 Aug 2026): closes the `Cannot read properties
            // of null (reading 'includes')` console error thrown from
            // `Proxy.groupIsCollapsed` on every panel page, including
            // `/admin/login`.
            //
            // Root cause, traced against the installed `filament/filament`
            // v5.7.3 sources (not guessed): Alpine's sidebar store persists
            // `collapsedGroups` via `Alpine.$persist(null).as('collapsedGroups')`
            // (`vendor/filament/filament/resources/js/stores/sidebar.js`).
            // Its default stays the literal `null` until something writes an
            // array to `localStorage['collapsedGroups']`. The ONLY code that
            // ever writes that key is an inline `<script>` embedded in
            // `resources/views/livewire/sidebar.blade.php` — which renders
            // only on pages that actually have a sidebar. The Alpine store
            // itself, though, is registered globally and exists on EVERY
            // page, including `/admin/login` (no sidebar there). So a
            // visitor's very first request against a browser with no prior
            // `collapsedGroups` value — most commonly hitting `/login` first
            // — creates the store with `collapsedGroups` still `null`. If
            // the NEXT page (e.g. the post-login dashboard, reached via a
            // Livewire `wire:navigate` SPA transition rather than a full
            // reload) is the first one to render a `<li
            // x-bind:class="{ 'fi-collapsed': $store.sidebar.
            // groupIsCollapsed(label) }">` sidebar-group element — which
            // happens for EVERY group, including the unlabeled default group
            // `Filament\Navigation\NavigationBuilder::getNavigation()` wraps
            // every ungrouped nav item in — the store object already exists
            // in memory from the login page with `collapsedGroups` still
            // `null` (Alpine does not re-read localStorage into an existing
            // store on a SPA transition), so `null.includes(label)` throws.
            // No navigation group in this panel is misconfigured — grepping
            // this whole app finds zero `->navigationGroup()`/`NavigationGroup::make()`
            // calls anywhere, so every nav item goes through that same
            // unlabeled-default-group path; this is a first-visit Alpine/
            // localStorage race, not a per-resource bug.
            //
            // Fix: seed `localStorage['collapsedGroups']` to `[]` from
            // `<head>`, on every request, before Alpine ever runs. `HEAD_END`
            // is rendered by `vendor/filament/filament/resources/views/
            // components/layout/base.blade.php`, which BOTH the full app
            // layout and the login/"simple" layout extend — so this hook
            // fires on `/admin/login` too, closing the race regardless of
            // which page a visitor's browser reaches first.
            //
            // CSP-nonce fix (26 Aug 2026): this renderHook's own inline
            // `<script>` had no `nonce` attribute at all — trunk's own CI
            // caught it via `CspNonceCoversEveryInlineTagTest`, which failed
            // on `/admin/login` and the authenticated `/admin` dashboard
            // (both render this hook) while `/vendor/login` and `/vendor`
            // stayed green, since `VendorPanelProvider` has no equivalent
            // hook. `ReportContentSecurityPolicy::handle()` seeds the
            // request's nonce via `app(Vite::class)->useCspNonce()` before
            // this closure ever runs, so `Vite::cspNonce()` here returns the
            // exact same value the response header carries — the same
            // mechanism `resources/views/vendor/filament/assets.blade.php`
            // and the other PR #203 view overrides already use, just called
            // from PHP instead of Blade (a renderHook closure returns a raw
            // string, not a compiled view).
            ->renderHook(
                PanelsRenderHook::HEAD_END,
                function (): string {
                    $nonce = Vite::cspNonce();

                    return <<<HTML
                        <script nonce="{$nonce}">
                            try {
                                if (localStorage.getItem('collapsedGroups') === null) {
                                    localStorage.setItem('collapsedGroups', JSON.stringify([]));
                                }
                            } catch (e) {
                                // Storage unavailable (private browsing, disabled
                                // storage, etc.) — Alpine's own \$persist already
                                // degrades to its in-memory default in that case,
                                // so there is nothing further to do here.
                            }
                        </script>
                        HTML;
                },
            );
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
