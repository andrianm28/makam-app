<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\CareSubscription;

use App\Domain\CareSubscription\CarePlanFrequency;
use App\Domain\CareSubscription\CycleScheduler;
use App\Domain\CareSubscription\Models\CarePlan;
use App\Domain\CareSubscription\Models\Subscription;
use App\Domain\CareSubscription\Models\SubscriptionCycle;
use App\Domain\CareSubscription\SubscriptionCycleStatus;
use App\Domain\CareSubscription\SubscriptionStatus;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class CycleSchedulerTest extends TestCase
{
    use RefreshDatabase;

    private function makeCarePlan(): CarePlan
    {
        return CarePlan::query()->create([
            'reference' => 'CP-'.Str::upper(Str::random(8)),
            'name' => 'Test Plan',
            'product_code' => 'GRAVE_CARE_MONTHLY',
            'frequency' => CarePlanFrequency::Monthly->value,
            'price_minor' => 150000,
            'currency' => 'IDR',
            'checklist_template' => [],
            'status' => 'active',
        ]);
    }

    private function makeActiveSubscription(CarbonImmutable $startedAt, int $currentCycle = 0): Subscription
    {
        return Subscription::query()->create([
            'reference' => 'SUB-'.Str::upper(Str::random(8)),
            'grave_id' => (string) Str::uuid(),
            'care_plan_id' => $this->makeCarePlan()->getKey(),
            'customer_id' => User::factory()->create()->id,
            'status' => SubscriptionStatus::Active->value,
            'frequency' => CarePlanFrequency::Monthly->value,
            'price_minor' => 150000,
            'currency' => 'IDR',
            'current_cycle_number' => $currentCycle,
            'started_at' => $startedAt,
        ]);
    }

    public function test_generates_cycles_for_due_subscriptions(): void
    {
        $startedAt = CarbonImmutable::now()->subMonthsNoOverflow(2)->startOfMonth();
        $subscription = $this->makeActiveSubscription($startedAt, 1);

        $scheduler = app(CycleScheduler::class);
        $cycles = $scheduler->generateDueCycles();

        $this->assertCount(1, $cycles);
        $this->assertSame(SubscriptionCycleStatus::Scheduled->value, $cycles->first()->status);
    }

    /**
     * FIXED 5 Sep 2026 (real bug, found by independent code audit): the
     * scheduler used to multiply by `current_cycle_number + 1` instead of
     * `current_cycle_number`, silently skipping cycle #2's real calendar
     * month for every subscription, every frequency, immediately after its
     * first payment. `test_generates_cycles_for_due_subscriptions` above
     * only ever asserted cycle *count*, which the bug didn't change (a
     * cycle was still generated -- just dated one interval too late) --
     * this test is what actually pins the real date.
     */
    public function test_the_second_cycle_starts_exactly_one_interval_after_the_first_never_skipping_the_interval_between(): void
    {
        // Cycle #1 was paid THIS month -- started_at is set the moment
        // cycle #1 is paid (MarkCyclePaid::__invoke()), and
        // current_cycle_number becomes 1 at that same instant.
        $cycleOneStart = CarbonImmutable::now()->subMonthsNoOverflow(1)->startOfMonth();
        $subscription = $this->makeActiveSubscription($cycleOneStart, 1);

        $scheduler = app(CycleScheduler::class);
        $cycles = $scheduler->generateDueCycles();

        $this->assertCount(1, $cycles);

        $secondCycle = $cycles->first()->fresh();

        // Cycle #2 must start EXACTLY one month after cycle #1's start --
        // the pre-fix formula would compute two months after instead,
        // permanently orphaning the calendar month in between.
        $this->assertTrue(
            CarbonImmutable::parse($secondCycle->cycle_start)->equalTo($cycleOneStart->addMonth()),
            "Expected cycle #2 to start at {$cycleOneStart->addMonth()->toDateString()} (one interval after cycle #1), got {$secondCycle->cycle_start}."
        );
    }

    public function test_is_idempotent_on_rerun(): void
    {
        $startedAt = CarbonImmutable::now()->subMonthsNoOverflow(2)->startOfMonth();
        $subscription = $this->makeActiveSubscription($startedAt, 1);

        $scheduler = app(CycleScheduler::class);
        $firstRun = $scheduler->generateDueCycles();
        $secondRun = $scheduler->generateDueCycles();

        $this->assertCount(1, $firstRun);
        $this->assertCount(1, $secondRun);

        $totalCycles = SubscriptionCycle::query()
            ->where('subscription_id', $subscription->getKey())
            ->count();

        $this->assertSame(1, $totalCycles);
    }

    public function test_skips_active_subscription_with_no_due_cycle(): void
    {
        // A domain-realistic "not yet due" fixture: cycle #1 was paid THIS
        // month (current_cycle_number is always >= 1 the instant a
        // subscription goes Active -- see MarkCyclePaid::__invoke()'s
        // atomic Draft->Active transition; "Active with cycle_number 0" is
        // not a reachable state and is not what this test means to cover).
        // The next cycle is due one month from now -- still in the future.
        $startedAt = CarbonImmutable::now()->startOfMonth();
        $subscription = $this->makeActiveSubscription($startedAt, 1);

        $scheduler = app(CycleScheduler::class);
        $cycles = $scheduler->generateDueCycles();

        $this->assertCount(0, $cycles);
    }

    public function test_skips_non_active_subscriptions(): void
    {
        $subscription = Subscription::query()->create([
            'reference' => 'SUB-'.Str::upper(Str::random(8)),
            'grave_id' => (string) Str::uuid(),
            'care_plan_id' => $this->makeCarePlan()->getKey(),
            'customer_id' => User::factory()->create()->id,
            'status' => SubscriptionStatus::Draft->value,
            'frequency' => CarePlanFrequency::Monthly->value,
            'price_minor' => 150000,
            'currency' => 'IDR',
            'current_cycle_number' => 0,
            'started_at' => CarbonImmutable::now()->subMonthsNoOverflow(2),
        ]);

        $scheduler = app(CycleScheduler::class);
        $cycles = $scheduler->generateDueCycles();

        $this->assertCount(0, $cycles);
    }
}
