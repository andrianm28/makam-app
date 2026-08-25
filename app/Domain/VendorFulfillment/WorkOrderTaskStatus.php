<?php

declare(strict_types=1);

namespace App\Domain\VendorFulfillment;

/**
 * Closed lifecycle states for `work_order_tasks` rows.
 */
enum WorkOrderTaskStatus: string
{
    case Pending = 'pending';

    case Completed = 'completed';

    /**
     * @var list<string>
     */
    public const array KNOWN_STATUSES = [
        self::Pending->value,
        self::Completed->value,
    ];
}
