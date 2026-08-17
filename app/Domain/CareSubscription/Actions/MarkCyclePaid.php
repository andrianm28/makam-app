<?php

declare(strict_types=1);

namespace App\Domain\CareSubscription\Actions;

use App\Domain\CareSubscription\CareSubscriptionAuditActions;
use App\Domain\CareSubscription\SubscriptionCycleStatus;
use App\Domain\CareSubscription\SubscriptionStatus;
use App\Domain\CareSubscription\Models\Subscription;
use App\Domain\CareSubscription\Models\SubscriptionCycle;
use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditOutcome;
use App\Platform\Audit\AuditSource;
use App\Platform\Audit\AuditSubject;
use App\Platform\Correlation\CorrelationContext;
use App\Platform\Outbox\Outbox;
use App\Platform\Outbox\OutboxClassification;

/**
 * Processes a validated webhook payment for a subscription cycle.
 *
 * Cycle → PAID. If this is the first paid cycle, subscription → ACTIVE.
 *
 * AC4: No status change from browser return URL — only webhook-validated.
 */
final readonly class MarkCyclePaid
{
    public function __invoke(
        SubscriptionCycle $cycle,
        string $paidSourceRef,
        string $actorReference,
    ): SubscriptionCycle {
        return Audit::wrap(
            mutation: function () use ($cycle, $paidSourceRef): SubscriptionCycle {
                $cycle->status = SubscriptionCycleStatus::Paid->value;
                $cycle->save();

                $invoice = $cycle->invoice;
                if ($invoice !== null) {
                    $invoice->status = 'paid';
                    $invoice->paid_at = now();
                    $invoice->save();
                }

                $subscription = Subscription::query()->find($cycle->subscription_id);

                if ($subscription !== null && $subscription->status === SubscriptionStatus::Draft->value) {
                    $subscription->status = SubscriptionStatus::Active->value;
                    $subscription->started_at = now();
                    $subscription->current_cycle_number = 1;
                    $subscription->save();
                } elseif ($subscription !== null && $subscription->status === SubscriptionStatus::Active->value) {
                    $subscription->current_cycle_number = $subscription->current_cycle_number + 1;
                    $subscription->save();
                }

                Outbox::record(
                    eventName: 'care.cycle_created.v1',
                    eventVersion: 1,
                    aggregateType: 'subscription_cycle',
                    aggregateId: $cycle->getKey(),
                    data: [
                        'subscription_id' => $cycle->subscription_id,
                        'cycle_id' => (string) $cycle->getKey(),
                        'paid_source_ref' => $paidSourceRef,
                    ],
                    classification: OutboxClassification::Internal,
                    idempotencyKey: "cycle:{$cycle->getKey()}",
                );

                return $cycle;
            },
            action: CareSubscriptionAuditActions::CYCLE_PAID,
            subject: fn (SubscriptionCycle $cycle): AuditSubject => new AuditSubject(
                'subscription_cycle',
                $cycle->getKey(),
            ),
            outcome: AuditOutcome::Allowed,
            actorRef: $actorReference,
            actorRole: 'system',
            source: AuditSource::Api,
            correlationId: app(CorrelationContext::class)->current()?->value,
        );
    }
}
