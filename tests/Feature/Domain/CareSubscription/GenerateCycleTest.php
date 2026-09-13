<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\CareSubscription;

use App\Domain\CareSubscription\Actions\GenerateCycle;
use App\Domain\CareSubscription\CarePlanFrequency;
use App\Domain\CareSubscription\Models\CarePlan;
use App\Domain\CareSubscription\Models\Subscription;
use App\Domain\CareSubscription\Models\SubscriptionCycle;
use App\Domain\CareSubscription\SubscriptionStatus;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The idempotent early-return path — see `GenerateCycleRaceTest` for the
 * real-PostgreSQL-race recovery-path test (ARCH-04), which cannot use
 * `RefreshDatabase` and therefore lives in its own class.
 */
final class GenerateCycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_the_existing_cycle_without_writing_when_one_already_exists(): void
    {
        $subscription = $this->createSubscription();
        $cycleStart = CarbonImmutable::parse('2026-09-01');
        $cycleEnd = CarbonImmutable::parse('2026-09-30');

        $first = app(GenerateCycle::class)($subscription, $cycleStart, $cycleEnd);
        $second = app(GenerateCycle::class)($subscription, $cycleStart, $cycleEnd);

        $this->assertSame($first->getKey(), $second->getKey());
        $this->assertSame(
            1,
            SubscriptionCycle::query()->where('subscription_id', $subscription->getKey())->count()
        );
    }

    private function createSubscription(): Subscription
    {
        $carePlan = CarePlan::query()->create([
            'reference' => 'CP-'.Str::upper(Str::random(8)),
            'name' => 'Perawatan Bulanan Standar',
            'product_code' => 'GRAVE_CARE_MONTHLY',
            'frequency' => CarePlanFrequency::Monthly->value,
            'price_minor' => 150000,
            'currency' => 'IDR',
            'checklist_template' => ['membersihkan makam'],
            'status' => 'active',
        ]);

        return Subscription::query()->create([
            'reference' => 'SUB-'.Str::upper(Str::random(8)),
            'grave_id' => (string) Str::uuid(),
            'care_plan_id' => $carePlan->getKey(),
            'customer_id' => User::factory()->create()->id,
            'status' => SubscriptionStatus::Active->value,
            'frequency' => CarePlanFrequency::Monthly->value,
            'price_minor' => $carePlan->price_minor,
            'currency' => 'IDR',
            'current_cycle_number' => 0,
            'started_at' => now()->subMonths(2),
        ]);
    }
}
