<?php

declare(strict_types=1);

namespace App\Platform\IdentityAccess\Mfa;

use Illuminate\Support\Facades\RateLimiter;

/**
 * AC6/AC9: "make MFA enrolment, challenge, recovery, and reset auditable
 * and rate-limited" / "rate-limit and record the attempt." Uses Laravel's
 * built-in `RateLimiter` facade — already available, no new dependency —
 * keyed by actor + IP, matching design.md's Security note: "Rate limit by
 * actor and IP."
 *
 * ---------------------------------------------------------------------------
 * Threshold chosen: 5 attempts per 60 seconds
 * ---------------------------------------------------------------------------
 * A conservative, documented default rather than an unbounded loop (the
 * batch brief's explicit instruction). 5/minute is generous enough that a
 * legitimate user mistyping a 6-digit code twice is never blocked, while
 * still bounding a brute-force attempt to a trivial rate (a 6-digit TOTP
 * code has 1,000,000 possibilities; even ignoring the 30-90s validity
 * window entirely, 5 guesses/minute is not a practical attack). Both
 * `MfaChallengeService` (TOTP) and `MfaRecoveryService` (recovery code) use
 * the SAME threshold/decay via this one class, keyed separately per
 * `$context` so a TOTP lockout does not also lock out recovery-code
 * attempts for the same actor, or vice versa.
 */
final class MfaRateLimiter
{
    private const int MAX_ATTEMPTS = 5;

    private const int DECAY_SECONDS = 60;

    public static function tooManyAttempts(string $context, int|string $actorRef, string $ip): bool
    {
        return RateLimiter::tooManyAttempts(self::key($context, $actorRef, $ip), self::MAX_ATTEMPTS);
    }

    /**
     * Record one attempt. Returns the number of attempts recorded so far in
     * the current decay window.
     */
    public static function hit(string $context, int|string $actorRef, string $ip): int
    {
        return RateLimiter::hit(self::key($context, $actorRef, $ip), self::DECAY_SECONDS);
    }

    /**
     * Reset the counter — called on a SUCCESSFUL verification so a
     * legitimate actor who mistyped a code once or twice is not left
     * artificially close to the threshold.
     */
    public static function clear(string $context, int|string $actorRef, string $ip): void
    {
        RateLimiter::clear(self::key($context, $actorRef, $ip));
    }

    public static function availableInSeconds(string $context, int|string $actorRef, string $ip): int
    {
        return RateLimiter::availableIn(self::key($context, $actorRef, $ip));
    }

    /**
     * @param  string  $context  A short discriminator (e.g. 'mfa-challenge',
     *                           'mfa-recovery') so different verification
     *                           mechanisms for the same actor+IP do not
     *                           share one bucket.
     */
    private static function key(string $context, int|string $actorRef, string $ip): string
    {
        return "{$context}:{$actorRef}:{$ip}";
    }
}
