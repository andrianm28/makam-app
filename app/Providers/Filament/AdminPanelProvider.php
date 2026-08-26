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
 * list, discover*() calls), which has been stable across recent major
 * versions in public documentation, but that is NOT the same as verifying
 * it compiles or boots against filament/filament v5.7.3 (the version this
 * repo's composer.lock pins). Confirm against the real package at
 * scaffold time, as design-system.md §8.3 already says to.
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
 * A SIXTH change (26 Aug 2026) REVERTED the SECOND deviation note above and
 * PR #174's font-provider fix, both as an explicit, informed decision by
 * the project owner: dark mode was restored to Filament's default
 * (system-preference) behaviour, and `provider: LocalFontProvider::class`
 * was removed from `->font()` so Filament's documented default
 * (`BunnyFontProvider`) took over again. `ReportContentSecurityPolicy` was
 * updated to allow `https://fonts.bunny.net` accordingly — see that
 * middleware's own doc block.
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
 * A FOURTH change (brand-identity-adoption Task 5, ADR-0034) wired the real
 * raster mark (`public/brand/mark-96.png`, Task 3) into the panel header
 * via `->brandLogo()`/`->brandLogoHeight()`, replacing Filament's default
 * text/icon brand. See the SEVENTH change below — this was reverted.
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
 *
 * A SEVENTH change (26 Aug 2026) REMOVES all brand-identity customisation
 * from this panel, an explicit, informed decision by the project owner —
 * NOT a correction of an error in the FOURTH change or in the OQ-09 palette
 * work described above. The admin and vendor Filament panels are internal
 * back-office tools, not part of the public-facing Earth-brown/Leaf-green
 * brand surface, and the owner decided they should render with Filament's
 * own stock, out-of-the-box appearance instead of a themed one:
 *
 *   1. `->colors($this->filamentColors())` is removed. This panel no
 *      longer loads the tokens.css-derived generated palette
 *      (app/Support/Design/generated/FilamentPalette.php) at all — it uses
 *      Filament's own default primary/gray colour scheme. The generated
 *      palette file and its `design:generate-filament-palette` /
 *      `design:verify-filament-palette` artisan commands (OQ-09) are left
 *      in the repo but have no remaining consumer; see design-system.md
 *      §8.3 for the record.
 *   2. `->font('Inter var')` is removed. This panel no longer requests a
 *      custom font family at all (self-hosted or Bunny-hosted) — it uses
 *      whatever default font stack Filament ships. This also supersedes
 *      the SIXTH change's font-provider reversal above: the question of
 *      "self-hosted Inter vs Bunny-hosted Inter" no longer applies, because
 *      there is no custom font family being requested by either name.
 *   3. `->viteTheme('resources/css/filament/admin/theme.css')` is removed.
 *      That theme file existed solely to inject brand colours (tokens.css)
 *      and the self-hosted Inter font into Filament's CSS build; it carried
 *      no non-branding structural fix, so it was deleted outright rather
 *      than partially stripped (`resources/css/filament/admin/theme.css`,
 *      removed; its Vite entry in `vite.config.js` removed with it). This
 *      panel now uses Filament's own default, package-shipped CSS build —
 *      no custom theme file of any kind.
 *   4. `->brandLogo()`/`->brandLogoHeight()` (the FOURTH change, ADR-0034
 *      Task 5) are removed. In their place, `->brandName('Makam Admin')` —
 *      a plain text label, not the designed wordmark/logo — is kept purely
 *      for functional identification (so an operator can tell which app
 *      they are in from the browser tab/header). This is a judgment call,
 *      not an explicit instruction; flagged in this batch's PR description
 *      for the project owner to confirm or correct.
 *
 * Dark mode itself is UNCHANGED by this SEVENTH change — the SIXTH change's
 * restoration of Filament's default system-preference dark mode stays
 * exactly as it was; this batch is about colour/font/logo branding only.
 * The `->renderHook(PanelsRenderHook::HEAD_END, ...)` call below (a
 * separate, unrelated UI/UX audit fix, 26 Aug 2026, PR #206 — closes a
 * `collapsedGroups`-localStorage race, see its own inline comment) is also
 * UNCHANGED by this batch — it has nothing to do with branding.
 *
 * `ReportContentSecurityPolicy`'s `https://fonts.bunny.net` allowance
 * (added by the SIXTH change) is intentionally left untouched by this
 * batch: CSP configuration is a security-sensitive change out of this
 * batch's scope, and the allowance is now simply unused rather than
 * actively wrong (no panel emits a Bunny font request any more, so no
 * request is ever made against it). Flagged in this batch's PR description
 * as a candidate for a follow-up cleanup, not addressed here.
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
            // SEVENTH change, 26 Aug 2026 — see this class's own doc block.
            // Plain text only, not the designed wordmark/logo — kept for
            // functional identification (which app is this?), a judgment
            // call flagged in this batch's PR description.
            ->brandName('Makam Admin')
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
}
