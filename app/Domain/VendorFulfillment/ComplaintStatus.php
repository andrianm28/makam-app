<?php

declare(strict_types=1);

namespace App\Domain\VendorFulfillment;

/**
 * The closed set of lifecycle states for a `service_complaints` row. Values
 * mirror the `service_complaints.status` PostgreSQL CHECK constraint.
 */
enum ComplaintStatus: string
{
    case Open = 'OPEN';
    case Investigating = 'INVESTIGATING';
    case Resolved = 'RESOLVED';
    case Dismissed = 'DISMISSED';
}
