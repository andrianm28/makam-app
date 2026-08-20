<?php

declare(strict_types=1);

namespace App\Platform\FinancialLedger;

use Carbon\CarbonImmutable;

/**
 * One `PayoutSummaryReport` run. Same metadata shape as `LedgerReportResult`
 * and `CashReceiptsReportResult` — see the former's doc block for why
 * `generatedAt` lives on the result object rather than in the rows.
 */
final readonly class PayoutSummaryReportResult
{
    /**
     * @param  string|list<string>|null  $entityRef  The scope the report was run
     *                                               for — see `LedgerReportResult::$entityRef`.
     * @param  list<array{id: string, vendor_id: string, entity_ref: string, amount_minor: int, method: string, state: string, occurred_at: string}>  $rows
     *                                                                                                                                                       One row per `payouts` row, ordered by `occurred_at` then `id`.
     */
    public function __construct(
        public string $period,
        public string|array|null $entityRef,
        public CarbonImmutable $generatedAt,
        public array $rows,
        public int $totalMinor,
    ) {}

    public function scopeLabel(): string
    {
        return match (true) {
            $this->entityRef === null => 'all',
            is_array($this->entityRef) => implode('|', $this->entityRef),
            default => $this->entityRef,
        };
    }
}
