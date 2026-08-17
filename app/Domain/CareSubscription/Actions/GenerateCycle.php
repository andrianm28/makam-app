<?php

declare(strict_types=1);

namespace App\Domain\CareSubscription\Actions;

use App\Domain\CareSubscription\CareSubscriptionAuditActions;
use App\Domain\CareSubscription\SubscriptionCycleStatus;
use App\Domain\CareSubscription\Models\Subscription;
use App\Domain\CareSubscription\Models\SubscriptionCycle;
use App\Domain\CareSubscription\Models\SubscriptionInvoice;
use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditOutcome;
use App\Platform\Audit\AuditSource;
use App\Platform\Audit\AuditSubject;
use App\Platform\Correlation\CorrelationContext;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;

/**
 * Generates a subscription cycle with a pending invoice.
 *
 * Idempotent: if a cycle with the same subscription_id + cycle_start + cycle_end
 * already exists, it is returned without modification.
 */
final readonly class GenerateCycle
{
    public function __invoke(
        Subscription $subscription,
        CarbonInterface $cycleStart,
        CarbonInterface $cycleEnd,
    ): SubscriptionCycle {
        $existing = SubscriptionCycle::query()
            ->where('subscription_id', $subscription->getKey())
            ->where('cycle_start', $cycleStart->toDateString())
            ->where('cycle_end', $cycleEnd->toDateString())
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return Audit::wrap(
            mutation: function () use ($subscription, $cycleStart, $cycleEnd): SubscriptionCycle {
                $cycle = SubscriptionCycle::query()->create([
                    'subscription_id' => $subscription->getKey(),
                    'cycle_start' => $cycleStart->toDateString(),
                    'cycle_end' => $cycleEnd->toDateString(),
                    'status' => SubscriptionCycleStatus::Scheduled->value,
                ]);

                SubscriptionInvoice::query()->create([
                    'subscription_cycle_id' => $cycle->getKey(),
                    'amount_minor' => $subscription->price_minor,
                    'currency' => $subscription->currency,
                    'status' => 'pending',
                    'issued_at' => now(),
                ]);

                return $cycle;
            },
            action: CareSubscriptionAuditActions::CYCLE_GENERATED,
            subject: fn (SubscriptionCycle $cycle): AuditSubject => new AuditSubject(
                'subscription_cycle',
                $cycle->getKey(),
            ),
            outcome: AuditOutcome::Allowed,
            actorRef: 'system',
            actorRole: 'system',
            source: AuditSource::Job,
            correlationId: app(CorrelationContext::class)->current()?->value,
        );
    }
}
