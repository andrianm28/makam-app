<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Platform\IdentityAccess\ActorContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Self-service MFA's login-time enforcement — see
 * `.superpowers/sdd/2026-08-09-mfa-reauthentication-integration/design.md`
 * Goal #2. Unconditional per-user (no FeatureGate): an authenticated actor
 * with a confirmed `MfaEnrolment` (`ActorContext::MFA_STATE_ENROLLED`) who
 * has not completed a challenge this session is redirected to the challenge
 * page. A non-enrolled or unauthenticated actor passes through untouched —
 * this middleware never blocks anyone who hasn't opted in to MFA.
 *
 * Distinct from `RequireRecentAuthentication`: that class gates one specific
 * sensitive ACTION behind a freshness window; this one gates panel ACCESS
 * itself behind "have you proven your second factor this session at all."
 * They compose independently and never call each other.
 *
 * ---------------------------------------------------------------------------
 * Route name — CONFIRMED (Task 4, built `App\Filament\Admin\Pages\MfaChallenge`)
 * ---------------------------------------------------------------------------
 * `'filament.admin.pages.mfa-challenge'` was Task 2's best guess, traced
 * against a real installed Filament 5.7.3 package but not fully verified
 * end-to-end at the time. Task 4 traced the full name-generation chain for
 * real against the same installed `filament/filament` v5.7.3 copy (a sibling
 * project on this host, `/home/ubuntu/platform-galang-dana-app/vendor/
 * filament/filament`) and confirmed this string is correct:
 *   - `routes/web.php:15` wraps every panel's routes in `Route::name('filament.')`.
 *   - `routes/web.php:27-30` nests `Route::name("{$panelId}.")` — `'admin.'`
 *     here, since this app has one domain (no multi-domain tenancy).
 *   - `src/Pages/Page.php:111-121` (`Page::registerRoutes()`, the base class
 *     every panel page — including `Pages\Dashboard` and `MfaChallenge` —
 *     extends): wraps non-clustered pages in `Route::name('pages.')`.
 *   - `src/Pages/Concerns/HasRoutes.php:44,56-59` (`routes()` /
 *     `getRelativeRouteName()`): names the route after the page's own slug
 *     (`'mfa-challenge'` for this page, set via `MfaChallenge::$slug`).
 *   - `src/Pages/Page.php:172-180` (`getRouteName()`) independently confirms
 *     the same concatenation: `'pages.' . getRelativeRouteName($panel)`,
 *     passed through `Panel::generateRouteName()`
 *     (`src/Panel/Concerns/HasRoutes.php:109-118`,
 *     `"filament.{$this->getId()}.{$domain}{$name}"`, `$domain` empty here).
 * Concatenated: `filament.` + `admin.` + `pages.` + `mfa-challenge` =
 * `filament.admin.pages.mfa-challenge` — exactly Task 2's guess. No
 * correction needed here or in the two Task 3 tests that already use it.
 */
final class EnforceMfaChallenge
{
    /**
     * Widened from `private` to `public` (Task 4) so `MfaChallenge`, the
     * page this middleware redirects to, can write this exact key via
     * `EnforceMfaChallenge::SESSION_KEY` instead of restating the literal
     * string a second time.
     */
    public const string SESSION_KEY = 'mfa_challenge_satisfied_at';

    /**
     * Named once, used for both the redirect target below and the exemption
     * that keeps this middleware from redirecting that target to itself.
     */
    public const string CHALLENGE_ROUTE_NAME = 'filament.admin.pages.mfa-challenge';

    public function handle(Request $request, Closure $next): Response
    {
        $actorContext = app(ActorContext::class);

        if (! $actorContext->isAuthenticated()) {
            return $next($request);
        }

        if ($actorContext->mfaState !== ActorContext::MFA_STATE_ENROLLED) {
            return $next($request);
        }

        if ($request->session()->has(self::SESSION_KEY)) {
            return $next($request);
        }

        // The challenge page is a panel route, so this middleware guards it
        // too — and an actor arrives there precisely BECAUSE they have no
        // session key yet. Without this exemption the page redirects to
        // itself (an actual redirect loop, so no enrolled actor can reach
        // the panel at all) and the `url.intended` write below overwrites
        // the sensitive action the actor was attempting with this page's own
        // URL.
        if ($request->route()?->getName() === self::CHALLENGE_ROUTE_NAME) {
            return $next($request);
        }

        $request->session()->put('url.intended', $request->fullUrl());

        return redirect()->route(self::CHALLENGE_ROUTE_NAME);
    }
}
