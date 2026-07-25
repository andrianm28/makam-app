<?php

declare(strict_types=1);

namespace App\Platform\IdentityAccess\Mfa\Totp;

/**
 * Outcome of `Totp::verify()` — carries the matched time-step counter (not
 * just a bare bool) because the caller (`Mfa\MfaChallengeService` /
 * `Mfa\MfaEnrolmentService`) MUST persist it as the new
 * `mfa_enrolments.last_verified_counter` on success, so the NEXT
 * verification call can reject a replay of this same step. See `Totp
 * ::verify()`'s own doc block for why that replay check exists.
 */
final class TotpVerificationResult
{
    private function __construct(
        public readonly bool $valid,
        public readonly ?int $counter,
    ) {}

    public static function success(int $counter): self
    {
        return new self(valid: true, counter: $counter);
    }

    public static function failure(): self
    {
        return new self(valid: false, counter: null);
    }
}
