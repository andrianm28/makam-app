<?php

declare(strict_types=1);

namespace App\Platform\IdentityAccess\Reauthentication;

use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditOutcome;
use App\Platform\Audit\AuditSource;
use App\Platform\Audit\AuditSubject;
use App\Platform\IdentityAccess\Reauthentication\Models\ReauthenticationEvent;
use Carbon\CarbonImmutable;

/**
 * `platform-identity-and-access` design.md: "Sensitive actions declare a
 * required freshness. Middleware compares `ActorContext.lastAuthenticatedAt`
 * and challenges when stale." This service is where BOTH halves of that
 * lifecycle get written:
 *
 * - `challenge()` — called by
 *   `App\Http\Middleware\RequireRecentAuthentication` the moment it detects
 *   a stale (or absent) `lastAuthenticatedAt` for a sensitive action. Writes
 *   one `reauthentication_events` row (`outcome = challenged`) AND one
 *   `audit_events` row via `Audit::record()` — the same dual-write pattern
 *   `Mfa\MfaChallengeService`/`Mfa\MfaRecoveryService` already established.
 * - `satisfy()` — the method a FUTURE controller calls once the actor has
 *   actually re-proved their identity, whichever mechanism it used. Writes
 *   the matching `outcome = satisfied` pair.
 *
 * No controller calling `satisfy()` exists in this repo yet (no login or
 * password-confirmation UI exists anywhere in this batch's scope, and none
 * of the six sensitive-action classes named in
 * `docs/security/authentication-and-mfa.md` §5 have a real
 * controller/route). This service only prepares the mechanism — see this
 * module's HUMAN-GATED safety constraint.
 *
 * ---------------------------------------------------------------------------
 * How a future controller decides WHICH re-proof mechanism satisfies a
 * challenge (MFA vs. password), and why this service does not decide it
 * ---------------------------------------------------------------------------
 * This service deliberately does NOT call
 * `Mfa\MfaChallengeService::challenge()` or `Mfa\MfaRecoveryService::redeem()`
 * itself. The batch brief's own framing is the reason: whether an actor
 * proves freshness via a TOTP/recovery-code challenge or via a password
 * re-entry form depends on `ActorContext::$mfaState` at the moment the
 * challenge page is shown — `MFA_STATE_ENROLLED` should prefer routing to
 * `Mfa\MfaChallengeService`, anything else falls back to password
 * re-confirmation (a UI this batch does not build; no login/password
 * form exists anywhere in this repo yet). That branch is a presentation
 * decision a real challenge controller needs to make with the actual HTTP
 * request/session in hand, not something this platform-level service can
 * or should decide on its own. What this service DOES guarantee is a
 * single, natural landing point for either path's success: a future
 * controller's `MfaChallengeService::challenge()` call returning
 * `$result->valid === true`, OR a future password-recheck form's own
 * successful `Hash::check()`, should both end by calling THIS class's
 * `satisfy()` — one place, one audit shape, regardless of which proof
 * mechanism was used.
 *
 * ---------------------------------------------------------------------------
 * Rate limiting — reuses `ReauthenticationRateLimiter` directly, but for a
 * different reason than MFA uses it for
 * ---------------------------------------------------------------------------
 * `ReauthenticationRateLimiter`'s public API (`tooManyAttempts()`/`hit()`/`clear()`,
 * keyed by a `$context` string + actor + IP) fits this module's keying
 * needs exactly, so it is reused as-is rather than duplicated — same
 * threshold/decay (5 attempts / 60 seconds) via the same class, under its
 * own `'reauthentication-challenge'` context so it shares no bucket with
 * MFA's own `'mfa-challenge'`/`'mfa-recovery'` contexts.
 *
 * The REASON for throttling is different from MFA's, though, and worth
 * spelling out: an MFA challenge attempt is a discrete, deliberate action
 * (a user submitting a 6-digit code), so `MfaChallengeService` logs every
 * attempt, including rate-limited ones — each attempt is itself meaningful
 * security signal. A re-authentication CHALLENGE, by contrast, is raised
 * automatically by middleware on every single inbound request while a
 * session is stale — an actor who is simply browsing (not attacking
 * anything) while stale would otherwise generate a new
 * `reauthentication_events` row AND a new `audit_events` row on every page
 * load. Rate limiting here therefore bounds WRITE volume, not the security
 * control itself: once the threshold is hit within the decay window, no
 * further row is written for the rest of that window, but the middleware's
 * redirect-and-preserve-intended-URL behaviour is completely unaffected by
 * this result — it must never depend on rate-limit state, or a request
 * flood could talk its way past the freshness check. See
 * `RequireRecentAuthentication`'s own doc block for the caller side of this.
 */
final class ReauthenticationService
{
    private const string RATE_LIMIT_CONTEXT = 'reauthentication-challenge';

    /**
     * @param  int|string|null  $actorRef  `ActorContext::$identityReference`
     *                                     — null for a guest request; still recorded (a stale/guest
     *                                     hit on a sensitive action is itself worth an event row).
     * @param  string  $actorRole  Required for every event, including a null
     *                             `$actorRef` — mirrors `Audit::record()`'s own rule.
     * @param  string  $reason  Free string naming the sensitive-action class
     *                          this challenge guards (e.g. 'bank_account_change',
     *                          'certificate_revoke') — not restated as an enum here; see the
     *                          migration's own doc block for why.
     */
    public function challenge(
        int|string|null $actorRef,
        string $actorRole,
        string $reason,
        AuditSource $source,
        string $ip = '0.0.0.0',
    ): ReauthenticationChallengeResult {
        $rateLimitKey = $actorRef ?? 'guest';

        if (ReauthenticationRateLimiter::tooManyAttempts(self::RATE_LIMIT_CONTEXT, $rateLimitKey, $ip)) {
            return ReauthenticationChallengeResult::rateLimited();
        }

        ReauthenticationRateLimiter::hit(self::RATE_LIMIT_CONTEXT, $rateLimitKey, $ip);

        $event = Audit::wrap(
            mutation: fn (): ReauthenticationEvent => ReauthenticationEvent::create([
                'actor_ref' => $actorRef !== null ? (string) $actorRef : null,
                'actor_role' => $actorRole,
                'reason' => $reason,
                'outcome' => ReauthenticationOutcome::CHALLENGED,
                'ip_address' => $ip,
                'occurred_at' => CarbonImmutable::now(),
            ]),
            action: ReauthenticationAuditActions::CHALLENGED,
            subject: fn (ReauthenticationEvent $event): AuditSubject => new AuditSubject('reauthentication_event', $event->id),
            // Denied: the sensitive action itself is being refused until a
            // fresh re-proof happens — the same "access refused for now"
            // semantics `Mfa\MfaAuditActions::CHALLENGE_FAILED` uses.
            outcome: AuditOutcome::Denied,
            actorRef: $actorRef,
            actorRole: $actorRole,
            source: $source,
            metadata: ['note' => $reason],
        );

        return ReauthenticationChallengeResult::recorded($event);
    }

    /**
     * Called once a future controller has confirmed the actor re-proved
     * their identity (an `Mfa\MfaChallengeService::challenge()` success, an
     * `Mfa\MfaRecoveryService::redeem()` success, or a future
     * password-recheck form's own successful check — see this class's
     * top-level doc block). Writes the matching `outcome = satisfied` pair
     * and clears this module's own rate-limit counter for the actor+IP, the
     * same "success clears the counter" courtesy `ReauthenticationRateLimiter::clear()`
     * already gives a legitimate actor elsewhere in this codebase.
     */
    public function satisfy(
        int|string|null $actorRef,
        string $actorRole,
        string $reason,
        AuditSource $source,
        string $ip = '0.0.0.0',
    ): ReauthenticationEvent {
        $event = Audit::wrap(
            mutation: fn (): ReauthenticationEvent => ReauthenticationEvent::create([
                'actor_ref' => $actorRef !== null ? (string) $actorRef : null,
                'actor_role' => $actorRole,
                'reason' => $reason,
                'outcome' => ReauthenticationOutcome::SATISFIED,
                'ip_address' => $ip,
                'occurred_at' => CarbonImmutable::now(),
            ]),
            action: ReauthenticationAuditActions::SATISFIED,
            subject: fn (ReauthenticationEvent $event): AuditSubject => new AuditSubject('reauthentication_event', $event->id),
            outcome: AuditOutcome::Allowed,
            actorRef: $actorRef,
            actorRole: $actorRole,
            source: $source,
            metadata: ['note' => $reason],
        );

        ReauthenticationRateLimiter::clear(self::RATE_LIMIT_CONTEXT, $actorRef ?? 'guest', $ip);

        return $event;
    }
}
