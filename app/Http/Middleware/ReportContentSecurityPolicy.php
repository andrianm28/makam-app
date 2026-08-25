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
            "script-src 'self' 'nonce-{$nonce}'",
            "style-src 'self' 'nonce-{$nonce}'",
            "img-src 'self' data:",
            "font-src 'self'",
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
