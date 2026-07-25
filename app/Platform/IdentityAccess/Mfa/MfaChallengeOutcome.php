<?php

declare(strict_types=1);

namespace App\Platform\IdentityAccess\Mfa;

use InvalidArgumentException;

/**
 * The closed list of values `mfa_challenges.outcome` may hold. Deliberately
 * separate from `App\Platform\Audit\AuditOutcome` (allowed/denied/failed) —
 * that enum is the generic three-way audit outcome shared across the whole
 * platform; this one is this module's own narrower, domain-specific
 * succeeded/failed pair for a single verification attempt row. Same
 * plain-string + app-layer-validation convention as `MfaEnrolmentStatus`.
 */
final class MfaChallengeOutcome
{
    public const string SUCCEEDED = 'succeeded';

    public const string FAILED = 'failed';

    /**
     * @var list<string>
     */
    public const array KNOWN_OUTCOMES = [
        self::SUCCEEDED,
        self::FAILED,
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
                "Unknown MFA challenge outcome [{$outcome}]. Known outcomes: ".implode(', ', self::KNOWN_OUTCOMES).'.'
            );
        }
    }
}
