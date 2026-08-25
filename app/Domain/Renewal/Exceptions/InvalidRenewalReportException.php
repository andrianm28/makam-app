<?php

declare(strict_types=1);

namespace App\Domain\Renewal\Exceptions;

use InvalidArgumentException;

/**
 * A `RenewalReport` was asked for a period it cannot honestly parse. Kept
 * distinct from the ledger, order, and vendor-report exceptions for the same
 * reason those stay distinct from each other.
 */
final class InvalidRenewalReportException extends InvalidArgumentException
{
    public static function forMalformedPeriod(string $period): self
    {
        return new self(
            "Renewal report period [{$period}] is malformed. Expected format YYYY-MM, e.g. 2026-08."
        );
    }
}
