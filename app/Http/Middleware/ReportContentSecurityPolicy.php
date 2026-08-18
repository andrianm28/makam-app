<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Foundation\Vite;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Public-beta readiness — closes finding N-2/OQ-11 (no CSP defined anywhere,
 * `docs/planning/sprint-plan.md` §13). Attaches `Content-Security-Policy-
 * Report-Only` to EVERY response, global middleware rather than a group
 * append, because it must cover the Filament `/admin` and `/vendor` panels
 * too — both declare their own explicit middleware arrays and do NOT go
 * through the `web` group at all (see `bootstrap/app.php`'s comment on
 * `AssignCorrelationId`), so a `web`-group append (the way `RateLimiting`'s
 * `public-guest` limiter is wired) would silently miss both panels.
 *
 * ---------------------------------------------------------------------------
 * Report-only, deliberately, not enforced
 * ---------------------------------------------------------------------------
 * `docs/superpowers/plans/2026-08-18-public-beta-release.md` Lane D7: ship
 * `Content-Security-Policy-Report-Only` first, observe for a few days, THEN
 * enforce. Livewire 4 (whose own frontend bundles Alpine) and Filament 5
 * are both new enough in this codebase, and exercised broadly enough only
 * by manual UAT so far (`docs/testing/release-gates.md`'s 60 checkboxes are
 * still unchecked), that enforcing blind risks silently breaking the admin
 * panel or a public journey with no warning — a report-only header changes
 * nothing about what the browser allows; it only asks the browser to warn.
 * `Content-Security-Policy` (enforcing) is a follow-up change, gated on
 * having actually watched this header for violations first.
 *
 * No `report-uri`/`report-to` directive is set: that would need a real
 * receiving endpoint, which is separate infrastructure this change does not
 * build. Every modern browser still logs a report-only violation to its own
 * devtools console with no reporting endpoint configured, which is
 * sufficient for the manual UAT pass (`docs/superpowers/plans/2026-08-18-
 * public-beta-release.md` Lane F1) this header is meant to inform.
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

        $response->headers->set('Content-Security-Policy-Report-Only', $this->policy($nonce));

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
            "frame-ancestors 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            'upgrade-insecure-requests',
        ];

        return implode('; ', $directives);
    }
}
