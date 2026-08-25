<?php

declare(strict_types=1);

namespace App\Domain\OrderWorkflow\Exceptions;

use InvalidArgumentException;

/**
 * An `OrderPeriodReport` was asked for a period it cannot honestly parse.
 * Kept distinct from `App\Platform\FinancialLedger\Exceptions\
 * InvalidLedgerReportException` for the same reason that class documents
 * staying distinct from `InvalidReconciliationException`: this error talks
 * about an orders report, not a ledger one, even though both validate the
 * same `YYYY-MM` shape.
 */
final class InvalidOrderPeriodReportException extends InvalidArgumentException
{
    public static function forMalformedPeriod(string $period): self
    {
        return new self(
            "Order report period [{$period}] is malformed. Expected format YYYY-MM, e.g. 2026-08."
        );
    }
}
