<?php

declare(strict_types=1);

namespace App\Domain\Marketplace\Exceptions;

use InvalidArgumentException;

/**
 * A `VendorFulfillmentReport` was asked for a period it cannot honestly
 * parse. Kept distinct from the ledger and order-report exceptions for the
 * same reason those stay distinct from each other — the error talks about a
 * vendor performance report, not a ledger or order one.
 */
final class InvalidVendorFulfillmentReportException extends InvalidArgumentException
{
    public static function forMalformedPeriod(string $period): self
    {
        return new self(
            "Vendor fulfillment report period [{$period}] is malformed. Expected format YYYY-MM, e.g. 2026-08."
        );
    }
}
