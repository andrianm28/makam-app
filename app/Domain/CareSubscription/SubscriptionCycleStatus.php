<?php

declare(strict_types=1);

namespace App\Domain\CareSubscription;

enum SubscriptionCycleStatus: string
{
    case Scheduled = 'SCHEDULED';
    case Invoiced = 'INVOICED';
    case Paid = 'PAID';
    case WorkScheduled = 'WORK_SCHEDULED';
    case Completed = 'COMPLETED';
    case Expired = 'EXPIRED';
}
