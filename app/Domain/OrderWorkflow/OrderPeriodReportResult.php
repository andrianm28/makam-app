<?php

declare(strict_types=1);

namespace App\Domain\OrderWorkflow;

use Carbon\CarbonImmutable;

/**
 * One `OrderPeriodReport` run — the metadata AC12-style discipline every
 * report in this codebase carries (period, generation time), matching
 * `App\Platform\FinancialLedger\LedgerReportResult`'s shape.
 */
final readonly class OrderPeriodReportResult
{
    /**
     * @param  list<array{status: string, total: int}>  $rows  One row per
     *                                                         `orders.status` value present in the period, ordered by status ascending.
     */
    public function __construct(
        public string $period,
        public CarbonImmutable $generatedAt,
        public array $rows,
        public int $total,
    ) {}
}
