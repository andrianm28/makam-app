<?php

declare(strict_types=1);

namespace App\Platform\FinancialLedger\Exceptions;

use InvalidArgumentException;

/**
 * A `LedgerReport` was asked for something it cannot honestly produce.
 *
 * Kept separate from `InvalidReconciliationException` on purpose: the
 * reconciliation period and the report period are both `YYYY-MM`, but a
 * reconciliation's malformed-period error talks about provider statements,
 * and a report's talks about report input. Coupling the report to the
 * reconciliation exception's wording would make a report caller read
 * reconciliation terminology for what is a report-input error.
 */
final class InvalidLedgerReportException extends InvalidArgumentException
{
    public static function forMalformedPeriod(string $period): self
    {
        return new self(
            "Ledger report period [{$period}] is malformed. Expected format YYYY-MM, "
            .'e.g. 2026-08.'
        );
    }
}
