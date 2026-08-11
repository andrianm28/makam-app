<?php

declare(strict_types=1);

namespace App\Platform\FinancialLedger;

use Carbon\CarbonImmutable;

/**
 * One `LedgerReport` run, with the metadata AC12 makes mandatory on every
 * financial report: the `period` (`YYYY-MM`) it covers, the `source` the
 * totals were derived from (this module's single `LedgerReport::SOURCE` value,
 * `journal`), and the `generated_at` moment it was produced. `kind` and
 * `entityRef` are the report's own identity.
 *
 * `generatedAt` is deliberately declared here on the REPORT OBJECT rather
 * than embedded in the exported bytes: a CSV must be reproducible from the
 * ledger (identical ledger + period → identical bytes), and a wall-clock
 * timestamp in the content would break that literally. The generation time is
 * still declared, audited, and available to every caller — AC12 says
 * "declare", not "stamp every row".
 */
final readonly class LedgerReportResult
{
    /**
     * @param  list<array{account_code: string, debit_total: int, credit_total: int, net: int}>  $rows
     *                                                                                                  Sorted by `account_code` ascending; `net` is `debit_total - credit_total`.
     */
    public function __construct(
        public string $kind,
        public string $period,
        public ?string $entityRef,
        public string $source,
        public CarbonImmutable $generatedAt,
        public array $rows,
    ) {}
}
