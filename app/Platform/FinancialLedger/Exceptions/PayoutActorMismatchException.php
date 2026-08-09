<?php

declare(strict_types=1);

namespace App\Platform\FinancialLedger\Exceptions;

use RuntimeException;

final class PayoutActorMismatchException extends RuntimeException
{
    public static function forField(string $field): self
    {
        return new self(
            "Caller-supplied payout {$field} does not match the authenticated server-side actor."
        );
    }
}
