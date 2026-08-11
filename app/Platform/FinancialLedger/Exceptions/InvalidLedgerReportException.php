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

    /**
     * @param  list<string>  $knownKinds
     */
    public static function forUnknownKind(string $kind, array $knownKinds): self
    {
        return new self(
            "Unknown ledger report kind [{$kind}]. Known kinds: "
            .implode(', ', $knownKinds).'.'
        );
    }

    /**
     * An explicitly empty entity-reference list. Refused rather than treated
     * as "no filter", because silently widening an empty scope to every badan
     * usaha is the exact failure mode `LedgerReadScope` exists to prevent.
     */
    public static function forEmptyEntityScope(): self
    {
        return new self(
            'A ledger report scoped to an empty list of badan usaha is refused. '
            .'Pass null for a deliberately unscoped report.'
        );
    }
}
