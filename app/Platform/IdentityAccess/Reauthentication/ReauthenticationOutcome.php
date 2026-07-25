<?php

declare(strict_types=1);

namespace App\Platform\IdentityAccess\Reauthentication;

use InvalidArgumentException;

/**
 * The closed list of values `reauthentication_events.outcome` may hold.
 * Deliberately separate from both `App\Platform\Audit\AuditOutcome`
 * (allowed/denied/failed) and `App\Platform\IdentityAccess\Mfa\
 * MfaChallengeOutcome` (succeeded/failed) — this one is this module's own
 * narrower, domain-specific pair for a single re-authentication-challenge
 * lifecycle event: the middleware raised a challenge, or a future
 * controller reported it satisfied. Same plain-string +
 * app-layer-validation convention as `MfaChallengeOutcome` /
 * `MfaVerificationMethod`.
 */
final class ReauthenticationOutcome
{
    public const string CHALLENGED = 'challenged';

    public const string SATISFIED = 'satisfied';

    /**
     * @var list<string>
     */
    public const array KNOWN_OUTCOMES = [
        self::CHALLENGED,
        self::SATISFIED,
    ];

    public static function isKnown(string $outcome): bool
    {
        return in_array($outcome, self::KNOWN_OUTCOMES, true);
    }

    /**
     * @throws InvalidArgumentException when `$outcome` is not one of
     *                                  `self::KNOWN_OUTCOMES`.
     */
    public static function assertKnown(string $outcome): void
    {
        if (! self::isKnown($outcome)) {
            throw new InvalidArgumentException(
                "Unknown reauthentication outcome [{$outcome}]. Known outcomes: ".implode(', ', self::KNOWN_OUTCOMES).'.'
            );
        }
    }
}
