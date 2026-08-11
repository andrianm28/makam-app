<?php

declare(strict_types=1);

namespace App\Platform\FinancialLedger;

use App\Platform\FinancialLedger\Exceptions\InvalidLedgerReportException;

/**
 * The closed list of `LedgerReport` kinds a `BulkFinancialExport` may produce —
 * the same plain-string + app-layer-validation convention as
 * `ReconciliationDecision` and every other closed list in this module.
 *
 * One kind exists in this batch: `summary`, the per-account totals report for
 * one `YYYY-MM` period (AC12). It is the financial report this lane owns the
 * presentation contract for (see `.kiro/specs/platform-financial-ledger/
 * tasks.md` §Design system). A future lane may extend this list deliberately;
 * it must never be widened to accept arbitrary caller-supplied strings.
 */
final class LedgerReportKind
{
    /**
     * Per-account debit/credit totals for one period, derived from
     * `journal_batches`/`journal_entries` only (AC6: never from mutable order
     * state).
     */
    public const string SUMMARY = 'summary';

    /**
     * @var list<string>
     */
    public const array KNOWN_KINDS = [
        self::SUMMARY,
    ];

    public static function isKnown(string $kind): bool
    {
        return in_array($kind, self::KNOWN_KINDS, true);
    }

    /**
     * Throws `InvalidLedgerReportException`, NOT a bare
     * `InvalidArgumentException`, since Task 9b.
     *
     * The bare type made an unknown `?kind=` at the export route an uncaught
     * 500: `FinanceExportController` cannot catch `InvalidArgumentException`
     * without also swallowing genuine programming errors from anywhere below
     * it, so it caught neither. `InvalidLedgerReportException` EXTENDS
     * `InvalidArgumentException`, so this narrows the type without breaking any
     * existing catch — and the malformed-period and unknown-kind refusals, two
     * halves of "this report request is not well formed", now share one type
     * the HTTP layer can map to a 400.
     *
     * @throws InvalidLedgerReportException when `$kind` is not one of
     *                                      `self::KNOWN_KINDS`.
     */
    public static function assertKnown(string $kind): void
    {
        if (! self::isKnown($kind)) {
            throw InvalidLedgerReportException::forUnknownKind($kind, self::KNOWN_KINDS);
        }
    }
}
