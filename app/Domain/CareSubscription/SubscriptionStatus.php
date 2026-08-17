<?php

declare(strict_types=1);

namespace App\Domain\CareSubscription;

/**
 * Closed lifecycle states for `subscriptions` rows.
 * design.md: DRAFT -> ACTIVE -> PAUSED -> ENDED -> CANCELLED
 */
enum SubscriptionStatus: string
{
    case Draft = 'draft';

    case Active = 'active';

    case Paused = 'paused';

    case Ended = 'ended';

    case Cancelled = 'cancelled';

    /**
     * @var list<string>
     */
    public const array KNOWN_STATUSES = [
        self::Draft->value,
        self::Active->value,
        self::Paused->value,
        self::Ended->value,
        self::Cancelled->value,
    ];
}
