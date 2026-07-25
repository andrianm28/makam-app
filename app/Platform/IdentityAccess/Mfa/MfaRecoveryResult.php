<?php

declare(strict_types=1);

namespace App\Platform\IdentityAccess\Mfa;

/**
 * Outcome of `MfaRecoveryService::redeem()`. `$noCodesRemaining` is a
 * DISTINCT, honestly-reported state from `$valid === false` alone — the
 * batch brief's explicit instruction: an actor who has exhausted every
 * recovery code needs a different message ("no recovery codes left,
 * contact support / re-enrol") than one who simply mistyped a code.
 */
final class MfaRecoveryResult
{
    private function __construct(
        public readonly bool $valid,
        public readonly bool $rateLimited,
        public readonly bool $noCodesRemaining,
    ) {}

    public static function success(): self
    {
        return new self(valid: true, rateLimited: false, noCodesRemaining: false);
    }

    public static function failure(): self
    {
        return new self(valid: false, rateLimited: false, noCodesRemaining: false);
    }

    public static function rateLimited(): self
    {
        return new self(valid: false, rateLimited: true, noCodesRemaining: false);
    }

    public static function noCodesRemaining(): self
    {
        return new self(valid: false, rateLimited: false, noCodesRemaining: true);
    }
}
