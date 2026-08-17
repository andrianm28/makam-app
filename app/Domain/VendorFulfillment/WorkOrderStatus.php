<?php

declare(strict_types=1);

namespace App\Domain\VendorFulfillment;

/**
 * Closed lifecycle states for `work_orders` rows.
 * Billing and work status are TWO separate indicators (AC2).
 */
enum WorkOrderStatus: string
{
    case Scheduled = 'scheduled';

    case InProgress = 'in_progress';

    case Completed = 'completed';

    case Missed = 'missed';

    case Complaint = 'complaint';

    /**
     * @var list<string>
     */
    public const array KNOWN_STATUSES = [
        self::Scheduled->value,
        self::InProgress->value,
        self::Completed->value,
        self::Missed->value,
        self::Complaint->value,
    ];
}
