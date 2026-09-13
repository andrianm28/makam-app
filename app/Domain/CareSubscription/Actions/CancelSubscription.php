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
 * Cancels a non-terminal subscription.
 *
 * ARCH-11: this doc block previously claimed "AC7: throws if cancel policy
 * is not configured," but no such check was ever implemented here — the
 * only guard below is the terminal-status check. requirements.md AC7 ("THE
 * SYSTEM SHALL NOT apply cancellation, pause, failed-payment, grace/dunning,
 * or price-change behavior until the corresponding policy is explicitly
 * configured") is enforced today by the Filament UI layer keeping the
 * cancel control disabled (`CancelSubscriptionAction`), since no
 * cancellation-policy configuration surface exists yet for this action
 * itself to check. If this action is ever called directly (a future
 * policy-aware caller, a console command, a job), it does NOT itself
 * re-verify AC7 — whoever calls it is responsible for that until a real
 * policy configuration exists to check here.
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
