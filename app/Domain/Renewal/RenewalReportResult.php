<?php

declare(strict_types=1);

namespace App\Domain\Renewal;

use Carbon\CarbonImmutable;

/**
 * One `RenewalReport` run.
 */
final readonly class RenewalReportResult
{
    /**
     * @param  list<array{status: string, total: int}>  $rows  One row per
     *                                                         `renewals.status` value present for the period, ordered by status ascending.
     */
    public function __construct(
        public string $period,
        public CarbonImmutable $generatedAt,
        public array $rows,
        public int $total,
    ) {}
}
