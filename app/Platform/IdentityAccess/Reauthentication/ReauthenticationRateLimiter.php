<?php

declare(strict_types=1);

namespace App\Platform\IdentityAccess\Reauthentication;

use Illuminate\Support\Facades\RateLimiter;

/**
 * Relocated from `App\Platform\IdentityAccess\Mfa\MfaRateLimiter` when the
 * MFA module was removed (see `docs/adr/0024-use-session-auth-and-mfa.md`'s
 * superseding note) — this class was never MFA-specific despite its old
 * name and namespace; `ReauthenticationService::challenge()`/`::satisfy()`
 * were already its real, sole surviving consumers. Behaviour is unchanged
 * from the original: a generic, static, actor+IP-keyed attempt limiter
 * using Laravel's built-in `RateLimiter` facade.
 *
 * ---------------------------------------------------------------------------
 * Threshold: 5 attempts per 60 seconds (unchanged from the original class)
 * ---------------------------------------------------------------------------
 * Conservative and documented rather than an unbounded loop. Generous
 * enough that a legitimate actor mistyping a password twice is never
 * blocked, while bounding brute-force attempts to a trivial rate.
 */
final class ReauthenticationRateLimiter
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
     * legitimate actor who mistyped once or twice is not left artificially
     * close to the threshold.
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
     * @param  string  $context  A short discriminator so different
     *                           verification mechanisms for the same
     *                           actor+IP do not share one bucket.
     */
    private static function key(string $context, int|string $actorRef, string $ip): string
    {
        return "{$context}:{$actorRef}:{$ip}";
    }
}
