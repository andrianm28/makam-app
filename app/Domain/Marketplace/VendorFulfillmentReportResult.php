<?php

declare(strict_types=1);

namespace App\Domain\Marketplace;

use Carbon\CarbonImmutable;

/**
 * One `VendorFulfillmentReport` run.
 */
final readonly class VendorFulfillmentReportResult
{
    /**
     * @param  list<array{vendor_id: string, vendor_name: string, total: int, completed: int, cancelled: int, complaints: int, completion_rate: float}>  $rows
     *                                                                                                                                                          One row per vendor with at least one `vendor_orders` row in the period,
     *                                                                                                                                                          ordered by `vendor_name` ascending. `completion_rate` is `completed / total`
     *                                                                                                                                                          (0.0 when `total` is 0, which cannot occur for a row present in this list).
     */
    public function __construct(
        public string $period,
        public CarbonImmutable $generatedAt,
        public array $rows,
    ) {}
}
