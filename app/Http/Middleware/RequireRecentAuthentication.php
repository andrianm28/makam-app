<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Platform\Audit\AuditSource;
use App\Platform\IdentityAccess\ActorContext;
use App\Platform\IdentityAccess\Reauthentication\ReauthenticationService;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * S3-T3 — `platform-identity-and-access` requirements.md AC3 / design.md's
 * "Re-authentication middleware on the six sensitive action classes."
 * Sibling of `AssignCorrelationId` (the only other `app/Http/Middleware/**`
 * class in this codebase, Batch 3.3) — same "resolve fresh via `app()`, no
 * constructor-cached state" discipline: this class has no constructor,
 * precisely to avoid the "resolved once, frozen for the process" gotcha
 * that discipline exists to prevent, even though a plain middleware is not
 * itself Eloquent.
 *
 * ---------------------------------------------------------------------------
 * CRITICAL — this class is PREPARED, not WIRED, as of this batch (S3-T3,
 * a HUMAN-GATED task per `docs/planning/agent-execution-plan.md`)
 * ---------------------------------------------------------------------------
 * This middleware is not appended to `bootstrap/app.php`'s `web` group, not
 * added to `AdminPanelProvider.php`'s middleware array, and not attached to
 * any route anywhere in this repo. It changes nothing about what any
 * existing request does today. Reachable only by test code
 * (`tests/Feature/IdentityAccess/Reauthentication/**`) via an ad-hoc fixture
 * route, exactly like `AssignCorrelationIdTest`'s own precedent — except
 * that test's ad-hoc route deliberately reuses the REAL `web` group (this
 * middleware IS wired there), where this one cannot, because wiring this
 * middleware anywhere real is exactly what the human gate forbids.
 *
 * A human/future batch wires this onto a real sensitive-action route once
 * one exists (none of the six classes named in
 * `docs/security/authentication-and-mfa.md` §5 have a real controller/route
 * in this repo yet — confirmed repeatedly across prior batches).
 *
 * ---------------------------------------------------------------------------
 * Middleware parameters (Laravel's own `ClassName::class.':param1,param2'`
 * mechanism — no `bootstrap/app.php` alias registration needed or added)
 * ---------------------------------------------------------------------------
 * `$reason` — the sensitive-action class this instance guards (a free
 * string, e.g. `'bank_account_change'`), passed straight through to
 * `ReauthenticationService::challenge()`'s own `$reason` parameter. Not
 * restated as an enum here for the same "don't restate a catalogue you
 * don't own" reason that parameter's own doc block gives.
 *
 * `$challengeRouteName` — the name of the route a future controller
 * registers to actually run the re-authentication challenge (password form
 * or `Mfa\MfaChallengeService`). This class does NOT guess, hardcode, or
 * register that route itself (`routes/web.php` is not owned by this
 * batch) — a future caller supplies its own real route name when it
 * attaches this middleware, e.g.:
 *
 *   Route::post('/admin/payouts/{payout}/approve', ...)
 *       ->middleware(RequireRecentAuthentication::class.':payout_approval,reauthentication.challenge');
 *
 * If no such route exists when this actually fires, `redirect()->route(...)`
 * throws Laravel's own `RouteNotFoundException` — an honest, loud failure
 * rather than a silently dead hardcoded URL (AC5's "no dead link" spirit,
 * borrowed from the feature-gate spec's own fallback discipline). Tests
 * register their own ad-hoc fixture route for this, the same pattern
 * `AssignCorrelationIdTest` already established for its own test-only route.
 *
 * ---------------------------------------------------------------------------
 * Freshness comparison and the null-timestamp safe default
 * ---------------------------------------------------------------------------
 * Reads `config('reauthentication.freshness_seconds')` on every call (never
 * cached on this stateless class) so a runtime `config([...])` override in
 * tests is genuinely honoured. A `null` `ActorContext::$lastAuthenticatedAt`
 * (no `actor_sessions` row at all, e.g. an actor who has never logged in
 * through a flow that populates one) is always treated as STALE, requiring
 * re-authentication — the batch brief's explicit instruction: "no timestamp
 * should mean STALE ... a null 'last authenticated at' must never be
 * treated as 'never expires'." Fail closed, not open.
 */
final class RequireRecentAuthentication
{
    public function handle(Request $request, Closure $next, string $reason, string $challengeRouteName): Response
    {
        $actorContext = app(ActorContext::class);

        if ($this->isFresh($actorContext)) {
            return $next($request);
        }

        // Only the Panel source exists for real in this repo today (no API
        // or job-triggered sensitive action exists yet). A future batch
        // wiring this onto an API-triggered action should extend this
        // middleware to accept `$source` as its own parameter rather than
        // widening this hardcoded value silently.
        app(ReauthenticationService::class)->challenge(
            actorRef: $actorContext->identityReference,
            // `ActorContext::$roles` is always `[]` today (that class's own
            // flagged gap — no owning local roles table exists yet), so
            // there is no real role name to read here. 'authenticated_actor'
            // / 'guest' is a placeholder satisfying `Audit::record()`'s
            // "actorRole is always required" rule, not a real role lookup —
            // same honesty gap this codebase already flags elsewhere for
            // `$roles`. Replace with a real role once that table exists.
            actorRole: $actorContext->isAuthenticated() ? 'authenticated_actor' : 'guest',
            reason: $reason,
            source: AuditSource::Panel,
            ip: $request->ip() ?? '0.0.0.0',
        );

        // Laravel's own post-login-redirect mechanism
        // (`Illuminate\Auth\Middleware\Authenticate` already uses this exact
        // `url.intended` session key) — reused here, not invented, so a
        // real future challenge controller's own `redirect()->intended()`
        // call sends the actor back to the sensitive action they were
        // attempting, not to the start.
        $request->session()->put('url.intended', $request->fullUrl());

        return redirect()->route($challengeRouteName);
    }

    private function isFresh(ActorContext $actorContext): bool
    {
        if ($actorContext->lastAuthenticatedAt === null) {
            return false;
        }

        $freshnessSeconds = (int) config('reauthentication.freshness_seconds');

        return $actorContext->lastAuthenticatedAt->isAfter(
            CarbonImmutable::now()->subSeconds($freshnessSeconds)
        );
    }
}
