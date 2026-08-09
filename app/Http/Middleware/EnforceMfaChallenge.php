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
 * Route name pending confirmation (Task 2, tracked for Task 4/5)
 * ---------------------------------------------------------------------------
 * `'filament.admin.pages.mfa-challenge'` is the best current guess, traced
 * against a real installed Filament 5.7.3 package but not fully verified
 * end-to-end (the panel-level route-name prefix was not confirmed). The task
 * that builds the actual `MfaChallenge` page is responsible for confirming
 * this string and correcting it here if it differs.
 */
final class EnforceMfaChallenge
{
    private const string SESSION_KEY = 'mfa_challenge_satisfied_at';

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

        $request->session()->put('url.intended', $request->fullUrl());

        return redirect()->route('filament.admin.pages.mfa-challenge');
    }
}
