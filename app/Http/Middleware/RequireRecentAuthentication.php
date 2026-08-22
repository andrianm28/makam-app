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
 * or code-based challenge). This class does NOT guess, hardcode, or
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
    /**
     * Carries `$reason` — the sensitive action that triggered THIS challenge
     * — to the challenge page, which otherwise has no way to know it: the
     * page is a separate request, and is the redirect target of
     * `EnforceMfaChallenge` too, whose challenges guard no sensitive action
     * at all.
     *
     * It has to be threaded, not guessed, because the reason is load-bearing
     * for authorization, not just for audit prose: sensitive actions check
     * `reauthentication_events` for a satisfied row carrying THEIR OWN
     * reason (`FinancialLedger\Actions\ManualPayout` and `BulkFinancialExport`
     * both do). A satisfied row with a generic reason would leave those
     * actions permanently refused.
     *
     * Written on every challenge redirect, so the value always names the
     * most recent challenge rather than an older abandoned one, and consumed
     * (`session()->pull()`) by `MfaChallenge` on success, so one challenge
     * yields proof for exactly one action.
     *
     * ---------------------------------------------------------------------
     * KNOWN LIMIT — this reaches the STALE actor only
     * ---------------------------------------------------------------------
     * The freshness check below returns early, BEFORE this key is written. So
     * an actor who is already fresh is waved through with no challenge, no
     * reason threaded, and therefore no per-action `satisfied` row ever
     * written for them.
     *
     * That matters because this middleware's freshness model
     * (`actor_sessions.last_authenticated_at`) and the reason-scoped model a
     * sensitive action enforces for itself (a `reauthentication_events` row
     * matching its own reason — `FinancialLedger\Actions\ManualPayout` and
     * `BulkFinancialExport` both query for one) are two DIFFERENT gates that
     * this key only partly reconciles. A freshly-logged-in actor passes this
     * middleware and is then refused by the action's own check, with no way
     * to satisfy it: visiting the challenge page voluntarily mints only the
     * generic `MfaChallenge::REAUTHENTICATION_REASON`, never a per-action
     * reason, because no challenge for that action was ever raised.
     *
     * That refusal fails closed and is not a security hole, but it is a real
     * functional gap. Closing it means either having the sensitive action
     * raise its own challenge when its reason-scoped check fails, or
     * unifying the two freshness models — a design decision deliberately
     * left to a follow-up rather than smuggled into this middleware. Do not
     * read this key as evidence that reason-scoped gates are fully wired.
     */
    public const string REASON_SESSION_KEY = 'reauthentication_pending_reason';

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
        $request->session()->put(self::REASON_SESSION_KEY, $reason);

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
