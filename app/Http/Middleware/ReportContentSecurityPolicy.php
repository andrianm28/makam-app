<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Foundation\Vite;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Public-beta readiness — closes finding N-2/OQ-11 (no CSP defined anywhere,
 * `docs/planning/sprint-plan.md` §13). Attaches `Content-Security-Policy` to
 * EVERY response, global middleware rather than a group append, because it
 * must cover the Filament `/admin` and `/vendor` panels too — both declare
 * their own explicit middleware arrays and do NOT go through the `web`
 * group at all (see `bootstrap/app.php`'s comment on `AssignCorrelationId`),
 * so a `web`-group append (the way `RateLimiting`'s `public-guest` limiter
 * is wired) would silently miss both panels.
 *
 * ---------------------------------------------------------------------------
 * Enforcing, not report-only — flipped 25 Aug 2026 (SEC-08)
 * ---------------------------------------------------------------------------
 * `docs/superpowers/plans/2026-08-18-public-beta-release.md` Lane D7
 * originally shipped this as `Content-Security-Policy-Report-Only`,
 * deliberately, gated on "having actually watched this header for
 * violations first." That watch happened the hard way rather than through
 * a formal `report-uri` pipeline (none exists — see below): an extensive,
 * same-day live UAT pass exercised the public booking/renewal/marketplace
 * journeys and the `/admin` and `/vendor` panels directly against
 * production, with no reported CSP violation anywhere. Combined with a
 * repo-wide grep finding zero inline `<script>`/`<style>` tags without the
 * nonce, zero inline event-handler attributes, and zero third-party
 * resource references outside the one deliberate Maps `frame-src`
 * exception, that closes Lane D7's own stated gate.
 *
 * Residual gap, carried forward honestly rather than silently: no
 * `report-uri`/`report-to` is configured (see below), so a violation this
 * pass missed will now be a SILENT failure for the affected user — blocked
 * by the browser with nothing surfacing in any server-side monitoring, only
 * that user's own devtools console. Worth real report-uri infrastructure as
 * a follow-up; not built here.
 *
 * No `report-uri`/`report-to` directive is set: that would need a real
 * receiving endpoint, which is separate infrastructure this change does not
 * build.
 *
 * ---------------------------------------------------------------------------
 * Why a nonce, not `'unsafe-inline'`
 * ---------------------------------------------------------------------------
 * `Vite::useCspNonce()` generates one random nonce for this request and
 * applies it to every tag `@vite()` emits; Livewire's own asset injector
 * (`vendor/livewire/livewire/src/Mechanisms/FrontendAssets/
 * FrontendAssets.php`) already reads `Vite::cspNonce()` for its injected
 * `<script>`/`<style>` tags with no change needed on this codebase's side.
 * Generating the nonce here, before `$next($request)` runs, is what lets
 * the view layer's `@vite()`/Livewire calls pick it up — after would be too
 * late. This app has no external CDN/font dependency (`resources/js/
 * app.js`'s own doc block: "deliberately dependency-free vanilla JS"; grepped
 * for any `https://` reference in `resources/views`/`resources/js` outside
 * this codebase's own domain and W3C/schema.org namespace URIs — none), so
 * a nonce-scoped policy with no third-party script/style origin is
 * achievable from day one, rather than the wider `'unsafe-inline'` a CDN-
 * heavy app would be forced into.
 *
 * `style-src` also carries the nonce, not just `script-src`: Livewire
 * injects an inline `<style>` tag of its own (same `FrontendAssets.php`),
 * which needs it too.
 *
 * ---------------------------------------------------------------------------
 * `unsafe-eval` IS present — Filament's own Alpine usage genuinely needs it
 * ---------------------------------------------------------------------------
 * The first attempt at this switch shipped `script-src` with no
 * `unsafe-eval` at all, relying on Livewire's official CSP-safe bundle
 * (`config('livewire.csp_safe', true)`, which serves `livewire.csp.js`
 * instead of the regular `livewire.js` — built without `eval`/
 * `new Function()`, at the cost of a restricted directive grammar: no
 * arbitrary JS expressions, only plain method calls, literal arguments,
 * simple assignments, and a fixed set of magics). A repo-wide grep of this
 * app's OWN `resources/views/` confirmed every `wire:*`/`x-*` directive
 * this codebase writes is within that restricted grammar — but that grep
 * never covered `vendor/filament/**`, and Filament's own bundled views use
 * expressions the restricted grammar cannot parse at all:
 * `x-on:click="$store.sidebar.close()"`,
 * `x-on:click="(theme = @js($theme)) && close()"`,
 * `x-on:click="window.matchMedia(...).matches && $store.sidebar.close()"`.
 * Livewire's JS asset is served from ONE fixed, shared, per-installation
 * URL (`Mechanisms\HandleRequests\EndpointResolver::scriptPath()`, hashed
 * from `APP_KEY`) with no route/panel context available to the request
 * that fetches it — the public site and both Filament panels all reference
 * the exact same script tag, so there is no way to serve the CSP-safe
 * bundle to public pages while serving the regular bundle to `/admin` and
 * `/vendor` without a session-based (or similarly indirect) cross-request
 * signal, which is real additional infrastructure this change does not
 * build. Confirmed the hard way, not theorised: the CSP-safe attempt's own
 * PR pushed CI green on every check except the real browser suite, whose
 * failures spanned admin login, vendor login, and Filament table-action
 * clicks — an unparseable expression anywhere in Alpine's page-wide boot
 * (present on every Filament page via the sidebar/theme-toggle markup)
 * throws during initialization and silently takes every OTHER directive on
 * that page down with it, not just the one with the complex expression.
 *
 * Given the shared-endpoint constraint, the real choice was `unsafe-eval`
 * site-wide or a real per-panel asset-routing feature this fix does not
 * warrant building blind. `unsafe-eval`'s actual exploitability here is
 * narrower than it sounds: `script-src` stays nonce-scoped with no
 * `unsafe-inline` and no third-party origin (besides the one deliberate
 * Maps `frame-src`), so an attacker still cannot inject an arbitrary
 * `<script>` tag or load a remote script — `unsafe-eval` alone only helps
 * an attacker who has ALREADY found a way to route a string into an
 * existing `eval`/`Function`/`setTimeout(string)` call already present in
 * this app's or Filament's own legitimate code (a narrower "gadget" class
 * of vulnerability), not "any injection becomes code execution." This is
 * the standard, documented trade-off strict-CSP guidance describes for
 * Alpine/Livewire-based admin UIs; it is not a step back to the pre-SEC-08
 * report-only posture, which blocked nothing at all.
 *
 * ---------------------------------------------------------------------------
 * `style-src-attr 'unsafe-inline'` — Filament's own JS mutates the `style`
 * ATTRIBUTE, and a bare `'unsafe-inline'` on `style-src` is NOT enough to
 * permit that while a nonce is also present
 * ---------------------------------------------------------------------------
 * Real enforcement (this file's own SEC-08 flip) surfaced a second,
 * distinct Filament/Alpine breakage the report-only period never caught,
 * for the same reason `unsafe-eval` above went undetected until enforcement
 * was live: report-only logs a violation but never blocks it, and nothing
 * here has a `report-uri` receiver (see above), so a report-only pass
 * cannot demonstrate a directive is SUFFICIENT — only that nothing was
 * observed missing it, which is not the same claim. Filament v5's bundled
 * Alpine stores (concretely, the sidebar-collapse store reached from the
 * same page-wide `x-on:click="$store.sidebar...`/`{ 'fi-collapsed':
 * $store.sidebar.groupIsCollapsed(label) }` markup the `unsafe-eval` note
 * above already covers) set CSS directly via `element.style.*`, i.e. they
 * write the `style="..."` HTML ATTRIBUTE at runtime, not a `<style>`
 * element or `<link rel=stylesheet>`. Confirmed via a real CI browser-suite
 * failure (run 32871721430, `e2e-renewal-external.spec.ts`, all 3 attempts,
 * deterministic not flaky): the blocked mutation left the admin sidebar
 * rendered as a broken full-viewport overlay, pushing real page content
 * (e.g. the renewal-order list's "Lihat" link) outside the viewport.
 *
 * FIRST ATTEMPT AT THIS FIX WAS WRONG, disproven by the real fix attempt's
 * own CI run (32873855280) rather than assumed correct from spec-reading:
 * simply adding `'unsafe-inline'` to the existing `style-src 'self'
 * 'nonce-{$nonce}'` line did NOT work. The real Chromium console message
 * captured in that run's Playwright trace says exactly why: "Applying
 * inline style violates the following Content Security Policy directive
 * 'style-src ... 'unsafe-inline''. Note that 'unsafe-inline' is ignored if
 * either a hash or nonce value is present in the source list." The
 * documented "nonce present → `'unsafe-inline'` is ignored" backward-
 * compatibility fallback is NOT scoped to `<style>` ELEMENTS only, as this
 * file's previous version of this note assumed — it applies to the whole
 * `style-src` source list, attribute checks included, because the fallback
 * is a property of the SOURCE LIST being matched, not of what kind of
 * "does this comply" check consults it. Adding `'unsafe-inline'` to a
 * source list that already carries a nonce is therefore a complete no-op
 * in every nonce-aware browser, for both elements and attributes — proven
 * live, not merely re-derived from the spec text after the fact.
 *
 * The actual fix is CSP3's `style-src-attr`/`style-src-elem` split: when
 * both are declared explicitly, a nonce-aware browser stops falling back to
 * `style-src` for element/attribute checks and consults each directive's
 * OWN source list instead — so `style-src-attr` can carry `'unsafe-inline'`
 * with NO nonce in the same list, where the "ignored if nonce present"
 * fallback simply does not trigger, while `style-src-elem` keeps the nonce
 * for Livewire's server-emitted `<style>` tag (see the "Why a nonce, not
 * 'unsafe-inline'" section above). `style-src` itself is left intact as the
 * fallback for the (now vanishingly rare) browser with no CSP3 support for
 * `style-src-attr`/`style-src-elem` at all — such a browser ignores those
 * two directives as unrecognised and uses `style-src`'s own list for
 * everything, same as before this fix; it is not the mechanism modern
 * evergreen browsers (which this Playwright suite and this app's real
 * traffic both run) actually use.
 *
 * None of this adds a third-party origin: `style-src-attr` is
 * `'unsafe-inline'` alone (no origin list — attribute style values are not
 * fetched from anywhere, so there is nothing for an origin to scope), and
 * `style-src-elem` carries the exact same `'self' 'nonce-{$nonce}'` the
 * combined `style-src` line already had. `'unsafe-inline'` for style only
 * permits setting CSS property VALUES through the DOM the page's own
 * script already controls; it is not `script-src`'s `'unsafe-inline'`
 * (which this policy still does NOT carry anywhere — see `script-src`
 * above) and grants no script execution path of its own.
 *
 * ---------------------------------------------------------------------------
 * `https://fonts.bunny.net` on `style-src`/`style-src-elem`/`font-src` —
 * REVERTED, 26 Aug 2026, an explicit informed owner decision, reopening a
 * third-party origin this policy previously excluded
 * ---------------------------------------------------------------------------
 * PR #174 (5aca419) made `AdminPanelProvider`/`VendorPanelProvider` pass
 * `provider: LocalFontProvider::class` to `->font('Inter var', ...)`
 * specifically so neither panel would emit any request to
 * `https://fonts.bunny.net` — Filament's default `BunnyFontProvider`
 * (which a custom font family resolves to without that override) leaked a
 * visitor's IP to a third party on every admin/vendor page load, on a page
 * that may be handling private case/order data (design-system.md §1.4:
 * self-hosted fonts only). That `provider:` argument is now deliberately
 * REMOVED from both panels (a separate, explicit owner decision — see each
 * panel provider's own doc block) — restoring Filament's documented
 * default, `BunnyFontProvider`. This middleware is updated to match:
 * omitting the CSP exception below would not restore the old look, it
 * would leave the font broken outright, since real CSP enforcement (SEC-08,
 * live since 25 Aug 2026) blocks an unlisted origin rather than merely
 * reporting it.
 *
 * Confirmed against the real installed `filament/filament`
 * `BunnyFontProvider::getHtml()`
 * (`vendor/filament/filament/src/FontProviders/BunnyFontProvider.php`),
 * not guessed: it emits two tags, both against the SAME origin —
 *
 *   <link rel="preconnect" href="https://fonts.bunny.net">
 *   <link href="https://fonts.bunny.net/css?family=inter-var:400,500,600,700&display=swap" rel="stylesheet" />
 *
 * The `rel="stylesheet"` tag is a stylesheet-fetching `<link>` element, so
 * it is governed by `style-src-elem` (and, for a browser with no CSP3
 * support for the split directives, by the `style-src` fallback line) —
 * NOT `style-src-attr`, which only covers the `style="..."` ATTRIBUTE
 * Filament's own Alpine code mutates (see the `style-src-attr` section
 * above); a stylesheet `<link>` is an element-level fetch, a different
 * concern entirely. `https://fonts.bunny.net` is added to both
 * `style-src`/`style-src-elem`'s source lists.
 *
 * The CSS that URL returns then declares `@font-face { src: url(...) }`
 * rules for the actual font files. Fetched for real (not assumed) against
 * `https://fonts.bunny.net/css?family=inter:400,500,600,700&display=swap`:
 * every one of its 56 `@font-face` rules' `url()` values also resolves to
 * `https://fonts.bunny.net` — unlike Google Fonts' two-origin split
 * (`fonts.googleapis.com` for CSS, `fonts.gstatic.com` for files), Bunny
 * Fonts serves both the stylesheet and the font files from the SAME single
 * origin. So exactly one origin, `https://fonts.bunny.net`, is added to
 * `font-src` too, and no second origin is needed anywhere.
 *
 * The `rel="preconnect"` tag is a resource hint, not a stylesheet or font
 * fetch — if a browser enforces `connect-src` against it and this policy's
 * `connect-src 'self'` blocks it, the tag simply fails to warm the
 * connection early; the actual stylesheet and font fetches below still
 * proceed and still succeed via `style-src-elem`/`font-src`, so
 * `connect-src` is deliberately left unchanged. Worth revisiting only if a
 * real console warning about the blocked preconnect turns out to matter in
 * practice; nothing observed shows it does.
 *
 * Known, accepted trade-off, mirroring the original fix's own trade-off in
 * reverse: this reopens `style-src`/`style-src-elem`/`font-src` to a real
 * third-party origin, `https://fonts.bunny.net` — the visitor-IP-leak
 * concern PR #174 closed is knowingly reaccepted, not resolved. Reversing
 * THIS change (closing the exception again) requires putting `provider:
 * LocalFontProvider::class` back on both panels' `->font()` calls at the
 * same time; removing only the CSP exception while the panels still emit
 * the Bunny `<link>` tags would just reintroduce the blocked-font bug this
 * revert is meant to avoid, and removing only the panels' provider
 * argument while leaving this exception in place is exactly today's
 * change and does what it says.
 *
 * ---------------------------------------------------------------------------
 * Filament's OWN inline `<style>`/`<script>` tags had no nonce at all —
 * fixed via three Blade view overrides, not a change to this file
 * ---------------------------------------------------------------------------
 * Found live, 26 Aug 2026: this file's earlier "Why a nonce" section's claim
 * that Livewire's own asset injector needs "no change needed on this
 * codebase's side" is true for LIVEWIRE's tags, but incomplete — it does not
 * extend to FILAMENT's own bundled Blade views, which turned out to emit
 * several literal inline `<style>`/`<script>` tags of their own with no
 * nonce at all (confirmed: zero `nonce` usage anywhere under
 * `vendor/filament`, and Filament has no first-class CSP nonce mechanism —
 * see filamentphp/filament#7032 and #8329, both open/unresolved). Since
 * `style-src-elem`/`script-src` above carry a nonce with no
 * `'unsafe-inline'` fallback, every one of those tags was blocked outright —
 * including the one that defines Filament's own `--fi-color-primary-*`/
 * `--danger-*` CSS custom properties, which is why every primary-colored
 * button sitewide (the panel login submit button included) silently
 * rendered invisible. Fixed at the VIEW layer, not here: three Laravel view
 * overrides (`resources/views/vendor/filament/assets.blade.php`,
 * `resources/views/vendor/filament-panels/components/layout/base.blade.php`,
 * `resources/views/vendor/filament-panels/livewire/sidebar.blade.php`) add
 * `nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}"` to each
 * un-nonced tag, replicating the exact mechanism Livewire's own tags already
 * use — see `docs/testing/release-gates.md` §H's SEC-08/CSP follow-up entry
 * for the full investigation trail, and
 * `tests/Feature/Http/Middleware/CspNonceCoversEveryInlineTagTest.php` for
 * the regression test (deliberately general — every inline tag in a real
 * rendered response must carry the header's nonce, not a list of the
 * specific tags found broken today).
 */
final class ReportContentSecurityPolicy
{
    public function handle(Request $request, Closure $next): Response
    {
        $nonce = app(Vite::class)->useCspNonce();

        $response = $next($request);

        $response->headers->set('Content-Security-Policy', $this->policy($nonce));

        return $response;
    }

    private function policy(string $nonce): string
    {
        $directives = [
            "default-src 'self'",
            // 'unsafe-eval' — see this class's own doc block for exactly
            // why: Filament's bundled Alpine genuinely needs it, and
            // Livewire's JS asset has no per-panel routing to avoid it
            // selectively. Still nonce-scoped, still no 'unsafe-inline',
            // still no third-party script origin.
            "script-src 'self' 'nonce-{$nonce}' 'unsafe-eval'",
            // Kept as the pre-CSP3 fallback for a browser that does not
            // recognise style-src-attr/style-src-elem below (which then
            // fall back to THIS line for both elements and attributes).
            // 'unsafe-inline' here is a genuine no-op in any nonce-aware
            // browser (nonce present in this same list — see this class's
            // own doc block for the real Chromium console message that
            // proved this the hard way), so it does nothing for the
            // browsers this app actually needs to support; it costs
            // nothing to leave for the legacy fallback case either.
            // https://fonts.bunny.net — REVERTED, 26 Aug 2026, an explicit
            // informed owner decision; see this class's own doc block's
            // "https://fonts.bunny.net ... REVERTED" section for the full
            // record (both panels' font provider reversal and exactly why
            // this origin, confirmed against the real installed
            // BunnyFontProvider source and a real fetch of Bunny's CSS).
            "style-src 'self' 'nonce-{$nonce}' 'unsafe-inline' https://fonts.bunny.net",
            // style-src-elem/style-src-attr — see this class's own doc
            // block for exactly why a bare 'unsafe-inline' on style-src
            // above does NOT permit Filament's Alpine-driven
            // `element.style.*` attribute mutation while a nonce is also
            // present: CSP3's fetch-directive split is the only way to give
            // attributes an 'unsafe-inline' source list that has no nonce
            // in it (so the "ignored if nonce present" fallback never
            // triggers) while elements keep the nonce Livewire's injected
            // `<style>` tag still needs. style-src-attr has no origin list
            // at all (attribute style values are not fetched from
            // anywhere). style-src-elem carries the same 'self' + nonce
            // style-src already had, PLUS https://fonts.bunny.net — the one
            // deliberate third-party exception, REVERTED 26 Aug 2026 (see
            // this class's own doc block), for the BunnyFontProvider
            // stylesheet `<link rel="stylesheet">` tag, which is an
            // element-level fetch, not an attribute mutation.
            "style-src-elem 'self' 'nonce-{$nonce}' https://fonts.bunny.net",
            "style-src-attr 'unsafe-inline'",
            // https://ui-avatars.com — Filament's default
            // `AvatarProviders\UiAvatarsProvider` (no custom
            // `->defaultAvatarProvider()` is configured on either panel)
            // fetches the signed-in user's initials avatar from this fixed
            // origin on every admin/vendor page load. Real enforcement
            // blocks it outright (previously silent under report-only, same
            // report-uri-less blind spot the style-src note above
            // describes). Scoped to exactly this one image host, nothing
            // wider — img-src stays otherwise 'self' plus inline `data:`.
            "img-src 'self' data: https://ui-avatars.com",
            // https://fonts.bunny.net — REVERTED, 26 Aug 2026, an explicit
            // informed owner decision; see this class's own doc block's
            // "https://fonts.bunny.net ... REVERTED" section for exactly
            // why this one origin covers every font FILE Bunny's CSS
            // response references (confirmed via a real fetch, not
            // assumed — Bunny Fonts serves stylesheet and files from the
            // same origin, unlike Google Fonts' two-origin split).
            "font-src 'self' https://fonts.bunny.net",
            "connect-src 'self'",
            // Cemetery directory detail pages embed a Google Maps iframe
            // (App\Domain\CemeteryDirectory\Models\Cemetery::embedMapUrl())
            // where a cemetery has real coordinates or a verified address —
            // without this, the embed would silently violate CSP under
            // 'self''s default-src fallback the moment enforcement mode is
            // ever turned on (currently Report-Only).
            'frame-src https://www.google.com',
            "frame-ancestors 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            'upgrade-insecure-requests',
        ];

        return implode('; ', $directives);
    }
}
