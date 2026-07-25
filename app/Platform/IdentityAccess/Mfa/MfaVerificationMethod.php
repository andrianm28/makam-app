<?php

declare(strict_types=1);

namespace App\Platform\IdentityAccess\Mfa;

use InvalidArgumentException;

/**
 * The closed list of values `mfa_challenges.method` may hold — which
 * mechanism a given verification attempt used. Same plain-string +
 * app-layer-validation convention as `MfaEnrolmentStatus` /
 * `Scopes\ScopeEntityType`.
 */
final class MfaVerificationMethod
{
    public const string TOTP = 'totp';

    public const string RECOVERY_CODE = 'recovery_code';

    /**
     * @var list<string>
     */
    public const array KNOWN_METHODS = [
        self::TOTP,
        self::RECOVERY_CODE,
    ];

    public static function isKnown(string $method): bool
    {
        return in_array($method, self::KNOWN_METHODS, true);
    }

    /**
     * @throws InvalidArgumentException when `$method` is not one of
     *                                  `self::KNOWN_METHODS`.
     */
    public static function assertKnown(string $method): void
    {
        if (! self::isKnown($method)) {
            throw new InvalidArgumentException(
                "Unknown MFA verification method [{$method}]. Known methods: ".implode(', ', self::KNOWN_METHODS).'.'
            );
        }
    }
}
