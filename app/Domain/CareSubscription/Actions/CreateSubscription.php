<?php

declare(strict_types=1);

namespace App\Domain\CareSubscription\Actions;

use App\Domain\CareSubscription\CarePlanFrequency;
use App\Domain\CareSubscription\CareSubscriptionAuditActions;
use App\Domain\CareSubscription\Models\CarePlan;
use App\Domain\CareSubscription\Models\Subscription;
use App\Domain\CareSubscription\SubscriptionStatus;
use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditOutcome;
use App\Platform\Audit\AuditSource;
use App\Platform\Audit\AuditSubject;
use App\Platform\Correlation\CorrelationContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Creates a subscription in DRAFT status with its first SCHEDULED cycle.
 *
 * No outbox event is emitted yet — the first payment triggers the event.
 */
final readonly class CreateSubscription
{
    public function __construct(private GenerateCycle $generateCycle) {}

    public function __invoke(
        CarePlan $carePlan,
        string $graveId,
        int $customerId,
        CarePlanFrequency $frequency,
        string $actorReference,
        string $actorRole,
    ): Subscription {
        return Audit::wrap(
            mutation: function () use ($carePlan, $graveId, $customerId, $frequency): Subscription {
                $subscription = Subscription::query()->create([
                    'reference' => 'SUB-'.Str::upper(Str::random(8)),
                    'grave_id' => $graveId,
                    'care_plan_id' => $carePlan->getKey(),
                    'customer_id' => $customerId,
                    'status' => SubscriptionStatus::Draft->value,
                    'frequency' => $frequency->value,
                    'price_minor' => $carePlan->price_minor,
                    'currency' => $carePlan->currency,
                    'current_cycle_number' => 0,
                ]);

                $cycleStart = CarbonImmutable::now()->startOfMonth();
                $cycleEnd = $this->calculateCycleEnd($cycleStart, $frequency);

                ($this->generateCycle)($subscription, $cycleStart, $cycleEnd);

                return $subscription;
            },
            action: CareSubscriptionAuditActions::SUBSCRIPTION_CREATED,
            subject: fn (Subscription $subscription): AuditSubject => new AuditSubject(
                'subscription',
                $subscription->getKey(),
            ),
            outcome: AuditOutcome::Allowed,
            actorRef: $actorReference,
            actorRole: $actorRole,
            source: AuditSource::Api,
            correlationId: app(CorrelationContext::class)->current()?->value,
        );
    }

    private function calculateCycleEnd(CarbonImmutable $start, CarePlanFrequency $frequency): CarbonImmutable
    {
        return match ($frequency) {
            CarePlanFrequency::Monthly => $start->addMonth()->subDay(),
            CarePlanFrequency::Quarterly => $start->addMonths(3)->subDay(),
            CarePlanFrequency::SemiAnnual => $start->addMonths(6)->subDay(),
            CarePlanFrequency::Annual => $start->addYear()->subDay(),
        };
    }
}
