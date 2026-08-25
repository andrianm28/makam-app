<?php

declare(strict_types=1);

namespace App\Platform\FinancialLedger;

use Carbon\CarbonImmutable;

/**
 * One `CashReceiptsReport` run. Same metadata shape as `LedgerReportResult`
 * (period, entity scope, generation time) — see that class's doc block for
 * why `generatedAt` lives on the result object rather than in the rows.
 */
final readonly class CashReceiptsReportResult
{
    /**
     * @param  string|list<string>|null  $entityRef  The scope the report was run
     *                                               for — see `LedgerReportResult::$entityRef`.
     * @param  list<array{business_key: string, source_type: string, source_id: string, entity_ref: string, occurred_at: string, amount_minor: int}>  $rows
     *                                                                                                                                                       One row per journal batch with a DR leg on `ChartOfAccounts::CASH_BANK_ACCOUNT`, ordered by `occurred_at` then `business_key`.
     */
    public function __construct(
        public string $period,
        public string|array|null $entityRef,
        public CarbonImmutable $generatedAt,
        public array $rows,
        public int $totalMinor,
    ) {}

    /**
     * Same derivation as `LedgerReportResult::scopeLabel()` — kept identical
     * rather than shared, because the two classes' `$entityRef` never mix at a
     * call site and a shared trait would be one more file to open to see a
     * three-line `match`.
     */
    public function scopeLabel(): string
    {
        return match (true) {
            $this->entityRef === null => 'all',
            is_array($this->entityRef) => implode('|', $this->entityRef),
            default => $this->entityRef,
        };
    }
}
