<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Public-beta readiness (Lane C3, `docs/superpowers/plans/
 * 2026-08-18-public-beta-release.md`). Global middleware, matching
 * `ReportContentSecurityPolicy`'s own reasoning for being one: it must
 * cover the Filament `/admin`/`/vendor` panels too, and both declare their
 * own middleware arrays outside the `web` group entirely
 * (`bootstrap/app.php`'s comment on `AssignCorrelationId`).
 *
 * `false` by default (`config('app.beta_noindex')`, backed by
 * `BETA_NOINDEX`) — this must never accidentally suppress indexing of the
 * real production site once it exists; only a deployment that explicitly
 * opts in emits the header at all.
 *
 * ---------------------------------------------------------------------------
 * App-level, not only an nginx `add_header` line — belt AND suspenders
 * ---------------------------------------------------------------------------
 * `dev.makam.co.id`'s vhost already sets this exact header at the nginx
 * layer (`ADR-0031`'s own precedent), and the plan's own text calls the
 * nginx header "authoritative and stronger" than `robots.txt` (which is
 * baked into one image shared by every vhost and can't be per-environment).
 * That reasoning still holds — nginx-level `add_header` should ALSO be set
 * on whatever vhost eventually fronts the beta deployment at cutover, and
 * this class does not replace that step.
 *
 * This middleware exists because cutover (DNS + a new/changed nginx vhost)
 * is a separate, explicitly human-reviewed step (`AGENTS.md` §Infrastructure-
 * agent execution — DNS/production-affecting changes) that has not
 * happened yet, while `.env.beta` (an application-layer file this codebase
 * already owns) can carry this protection from the moment the beta stack
 * first runs, before any vhost work happens at all. Two independent layers
 * are strictly safer than one: an nginx vhost misconfigured or
 * accidentally reverted at cutover still leaves this header in place.
 */
final class BetaNoindexTag
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (config('app.beta_noindex') === true) {
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive, nosnippet');
        }

        return $response;
    }
}
