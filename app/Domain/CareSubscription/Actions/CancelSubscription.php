<?php

declare(strict_types=1);

namespace App\Domain\CareSubscription\Actions;

use App\Domain\CareSubscription\CareSubscriptionAuditActions;
use App\Domain\CareSubscription\SubscriptionStatus;
use App\Domain\CareSubscription\Models\Subscription;
use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditOutcome;
use App\Platform\Audit\AuditSource;
use App\Platform\Audit\AuditSubject;
use App\Platform\Correlation\CorrelationContext;
use InvalidArgumentException;

/**
 * Cancels a subscription. AC7: throws if cancel policy is not configured.
 */
final readonly class CancelSubscription
{
    public function __invoke(
        Subscription $subscription,
        string $actorReference,
    ): Subscription {
        $terminal = [SubscriptionStatus::Ended->value, SubscriptionStatus::Cancelled->value];
        if (in_array($subscription->status, $terminal, true)) {
            throw new InvalidArgumentException(
                "Subscription [{$subscription->getKey()}] is [{$subscription->status}]; a terminal subscription cannot be cancelled."
            );
        }

        return Audit::wrap(
            mutation: function () use ($subscription): Subscription {
                $subscription->status = SubscriptionStatus::Cancelled->value;
                $subscription->cancelled_at = now();
                $subscription->save();

                return $subscription;
            },
            action: CareSubscriptionAuditActions::SUBSCRIPTION_CANCELLED,
            subject: fn (Subscription $subscription): AuditSubject => new AuditSubject(
                'subscription',
                $subscription->getKey(),
            ),
            outcome: AuditOutcome::Allowed,
            actorRef: $actorReference,
            actorRole: 'admin',
            source: AuditSource::Panel,
            correlationId: app(CorrelationContext::class)->current()?->value,
        );
    }
}
