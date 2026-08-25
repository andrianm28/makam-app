<?php

declare(strict_types=1);

namespace App\Domain\CareSubscription;

/**
 * Closed lifecycle states for `care_plans` rows.
 */
enum CarePlanStatus: string
{
    case Active = 'active';

    case Inactive = 'inactive';

    /**
     * @var list<string>
     */
    public const array KNOWN_STATUSES = [
        self::Active->value,
        self::Inactive->value,
    ];
}
