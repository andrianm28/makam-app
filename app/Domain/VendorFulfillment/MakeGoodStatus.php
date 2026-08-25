<?php

declare(strict_types=1);

namespace App\Domain\VendorFulfillment;

/**
 * The closed set of lifecycle states for a `make_good_orders` row. Values
 * mirror the `make_good_orders.status` PostgreSQL CHECK constraint.
 */
enum MakeGoodStatus: string
{
    case Pending = 'PENDING';
    case InProgress = 'IN_PROGRESS';
    case Completed = 'COMPLETED';
}
