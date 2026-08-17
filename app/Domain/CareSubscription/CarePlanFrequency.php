<?php

declare(strict_types=1);

namespace App\Domain\CareSubscription;

/**
 * Closed set of recurrence frequencies for care plans (AC1).
 */
enum CarePlanFrequency: string
{
    case Monthly = 'monthly';

    case Quarterly = 'quarterly';

    case SemiAnnual = 'semi_annual';

    case Annual = 'annual';

    /**
     * @var list<string>
     */
    public const array KNOWN_FREQUENCIES = [
        self::Monthly->value,
        self::Quarterly->value,
        self::SemiAnnual->value,
        self::Annual->value,
    ];
}
