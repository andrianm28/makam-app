<?php

declare(strict_types=1);

namespace App\Domain\CareSubscription;

use App\Domain\CareSubscription\Actions\GenerateCycle;
use App\Domain\CareSubscription\Models\Subscription;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Finds ACTIVE subscriptions where the next cycle is due and generates them.
 *
 * Idempotent: GenerateCycle handles dedup via the unique constraint.
 */
final readonly class CycleScheduler
{
    public function __construct(private GenerateCycle $generateCycle) {}

    public function generateDueCycles(): Collection
    {
        $subscriptions = Subscription::query()
            ->where('status', SubscriptionStatus::Active->value)
            ->get();

        $cycles = new Collection();

        foreach ($subscriptions as $subscription) {
            $nextCycleStart = $this->calculateNextCycleStart($subscription);

            if ($nextCycleStart === null) {
                continue;
            }

            $nextCycleEnd = $this->calculateCycleEnd($nextCycleStart, $subscription->frequency);

            $cycle = ($this->generateCycle)($subscription, $nextCycleStart, $nextCycleEnd);
            $cycles->push($cycle);
        }

        return $cycles;
    }

    private function calculateNextCycleStart(Subscription $subscription): ?CarbonImmutable
    {
        $frequency = CarePlanFrequency::from($subscription->frequency);
        $lastCycleEnd = $subscription->started_at
            ? CarbonImmutable::parse($subscription->started_at)->startOfMonth()
            : null;

        if ($lastCycleEnd === null) {
            return null;
        }

        $interval = match ($frequency) {
            CarePlanFrequency::Monthly => 1,
            CarePlanFrequency::Quarterly => 3,
            CarePlanFrequency::Semiannual => 6,
            CarePlanFrequency::Annual => 12,
        };

        $nextStart = $lastCycleEnd->addMonths($interval * $subscription->current_cycle_number);

        if ($nextStart->isFuture()) {
            return null;
        }

        return $nextStart;
    }

    private function calculateCycleEnd(CarbonImmutable $start, string $frequencyValue): CarbonImmutable
    {
        $frequency = CarePlanFrequency::from($frequencyValue);

        return match ($frequency) {
            CarePlanFrequency::Monthly => $start->addMonth()->subDay(),
            CarePlanFrequency::Quarterly => $start->addMonths(3)->subDay(),
            CarePlanFrequency::Semiannual => $start->addMonths(6)->subDay(),
            CarePlanFrequency::Annual => $start->addYear()->subDay(),
        };
    }
}
