<?php

declare(strict_types=1);

namespace App\Platform\FinancialLedger;

/**
 * What one `BulkFinancialExport` produced: ready-to-stream download bytes
 * plus the report metadata behind them.
 *
 * `contents` is the deterministic CSV (identical ledger + period + kind →
 * identical bytes — see `LedgerReportResult::generatedAt`'s doc block for
 * why the generation time is NOT embedded in it), `debitTotal`/`creditTotal`
 * are the report's grand totals (AC6: derived from journal only), and
 * `report` carries the AC12 declaration (period, source, generated_at).
 */
final readonly class BulkFinancialExportResult
{
    public function __construct(
        public string $filename,
        public string $mimeType,
        public string $contents,
        public int $debitTotal,
        public int $creditTotal,
        public LedgerReportResult $report,
    ) {}
}
