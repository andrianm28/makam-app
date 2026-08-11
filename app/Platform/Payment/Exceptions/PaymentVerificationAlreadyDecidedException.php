<?php

declare(strict_types=1);

namespace App\Platform\Payment\Exceptions;

use RuntimeException;

/**
 * AC8: "Admin verification SHALL be a separate authorized action" — a
 * `payment_verifications` row may be decided exactly once. Thrown by
 * `Models\PaymentVerification::decide()` when a caller (accidentally, via a
 * duplicate submit, or maliciously, via a replayed form post) attempts to
 * decide a row that is no longer `SUBMITTED`. Never caught silently — a
 * second decision attempt on an already-decided row is worth an honest 500,
 * not a quiet no-op that could mislead an admin into thinking their decision
 * took effect.
 */
final class PaymentVerificationAlreadyDecidedException extends RuntimeException
{
    public static function forStatus(string $status): self
    {
        return new self(
            "This payment verification has already been decided (status [{$status}]) and cannot be decided again."
        );
    }
}
