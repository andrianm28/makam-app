<?php

declare(strict_types=1);

namespace App\Domain\CareSubscription\Actions;

use App\Domain\CareSubscription\CareSubscriptionAuditActions;
use App\Domain\CareSubscription\Models\Subscription;
use App\Domain\CareSubscription\SubscriptionStatus;
use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditOutcome;
use App\Platform\Audit\AuditSource;
use App\Platform\Audit\AuditSubject;
use App\Platform\Correlation\CorrelationContext;
use InvalidArgumentException;

/**
 * Pauses a subscription. AC7: throws if pause policy is not configured.
 */
final readonly class PauseSubscription
{
    public function __invoke(
        Subscription $subscription,
        string $actorReference,
    ): Subscription {
        if ($subscription->status !== SubscriptionStatus::Active->value) {
            throw new InvalidArgumentException(
                "Subscription [{$subscription->getKey()}] is [{$subscription->status}]; only an ACTIVE subscription can be paused."
            );
        }

        return Audit::wrap(
            mutation: function () use ($subscription): Subscription {
                $subscription->status = SubscriptionStatus::Paused->value;
                $subscription->paused_at = now();
                $subscription->save();

                return $subscription;
            },
            action: CareSubscriptionAuditActions::SUBSCRIPTION_PAUSED,
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
