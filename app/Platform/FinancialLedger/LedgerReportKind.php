<?php

declare(strict_types=1);

namespace App\Platform\FinancialLedger;

use InvalidArgumentException;

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
     * @throws InvalidArgumentException when `$kind` is not one of
     *                                  `self::KNOWN_KINDS`.
     */
    public static function assertKnown(string $kind): void
    {
        if (! self::isKnown($kind)) {
            throw new InvalidArgumentException(
                "Unknown ledger report kind [{$kind}]. Known kinds: "
                .implode(', ', self::KNOWN_KINDS).'.'
            );
        }
    }
}
