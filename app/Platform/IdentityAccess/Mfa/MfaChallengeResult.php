<?php

declare(strict_types=1);

namespace App\Platform\IdentityAccess\Mfa;

/**
 * Outcome of `MfaChallengeService::challenge()`. Three distinct states,
 * not a bare bool — `$rateLimited` lets a caller show "too many attempts,
 * try again later" instead of a generic "wrong code" (AC9: "rate-limit and
 * record the attempt").
 */
final class MfaChallengeResult
{
    private function __construct(
        public readonly bool $valid,
        public readonly bool $rateLimited,
    ) {}

    public static function success(): self
    {
        return new self(valid: true, rateLimited: false);
    }

    public static function failure(): self
    {
        return new self(valid: false, rateLimited: false);
    }

    public static function rateLimited(): self
    {
        return new self(valid: false, rateLimited: true);
    }
}
