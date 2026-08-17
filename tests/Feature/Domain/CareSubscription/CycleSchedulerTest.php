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
            'customer_id' => (string) Str::uuid(),
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
        $startedAt = CarbonImmutable::now()->startOfMonth();
        $subscription = $this->makeActiveSubscription($startedAt, 0);

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
            'customer_id' => (string) Str::uuid(),
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
