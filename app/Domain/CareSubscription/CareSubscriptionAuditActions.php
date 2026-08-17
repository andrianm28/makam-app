<?php

declare(strict_types=1);

namespace App\Domain\CareSubscription;

/**
 * Audit action vocabulary for the care-subscription domain.
 */
final class CareSubscriptionAuditActions
{
    public const string CARE_PLAN_CREATED = 'CARE_PLAN_CREATED';

    public const string SUBSCRIPTION_CREATED = 'SUBSCRIPTION_CREATED';

    public const string CYCLE_GENERATED = 'CYCLE_GENERATED';

    public const string CYCLE_PAID = 'CYCLE_PAID';

    public const string SUBSCRIPTION_PAUSED = 'SUBSCRIPTION_PAUSED';

    public const string SUBSCRIPTION_CANCELLED = 'SUBSCRIPTION_CANCELLED';
}
