<?php

declare(strict_types=1);

namespace App\Domain\Renewal\Exceptions;

use RuntimeException;

/**
 * Raised when a privileged renewal write targets a renewal that is no
 * longer open — either already `DIBAYAR` (settled, one settlement per
 * period) or in a terminal state. The caller's transaction is expected to
 * roll back whatever it was doing; nothing in the audit trail may record
 * a settlement against an already-settled renewal.
 */
final class RenewalAlreadySettledException extends RuntimeException
{
    public static function forRenewal(string $renewalId): self
    {
        return new self("Renewal [{$renewalId}] is already settled.");
    }
}
