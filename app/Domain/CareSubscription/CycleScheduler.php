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

        $cycles = new Collection;

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

    /**
     * FIXED 5 Sep 2026: this used to multiply by `current_cycle_number + 1`,
     * silently skipping one full billing interval for every subscription's
     * first scheduled cycle after activation.
     *
     * `$anchor` (the subscription's first cycle's own start-of-month, from
     * `started_at` — set the moment cycle #1 is paid, see
     * `MarkCyclePaid::__invoke()`) is cycle #1 itself, at offset 0.
     * `current_cycle_number` counts how many cycles have been PAID so far
     * (1 immediately after cycle #1 is paid, per the same action), so the
     * next cycle to generate is always exactly `current_cycle_number`
     * intervals after the anchor — e.g. after cycle #1 is paid
     * (`current_cycle_number === 1`), the next due cycle is `$anchor +
     * 1 * $interval`, i.e. cycle #2, one interval later. Multiplying by
     * `current_cycle_number + 1` instead computed `$anchor + 2 *
     * $interval` at that exact point — jumping straight to cycle #3's real
     * calendar slot and permanently orphaning cycle #2's billing period,
     * for every subscription, every frequency. This was not a compounding
     * drift (every cycle after the skipped one was correctly spaced 1
     * interval apart again) — it was a one-time, permanent loss of exactly
     * one billing cycle's worth of revenue per subscription, immediately
     * after its first payment.
     */
    private function calculateNextCycleStart(Subscription $subscription): ?CarbonImmutable
    {
        $frequency = CarePlanFrequency::from($subscription->frequency);
        $anchor = $subscription->started_at
            ? CarbonImmutable::parse($subscription->started_at)->startOfMonth()
            : null;

        if ($anchor === null) {
            return null;
        }

        $interval = match ($frequency) {
            CarePlanFrequency::Monthly => 1,
            CarePlanFrequency::Quarterly => 3,
            CarePlanFrequency::SemiAnnual => 6,
            CarePlanFrequency::Annual => 12,
        };

        $nextStart = $anchor->addMonths($interval * $subscription->current_cycle_number);

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
            CarePlanFrequency::SemiAnnual => $start->addMonths(6)->subDay(),
            CarePlanFrequency::Annual => $start->addYear()->subDay(),
        };
    }
}
