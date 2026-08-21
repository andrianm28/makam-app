<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\CareSubscription;

use App\Domain\CareSubscription\Actions\CreateSubscription;
use App\Domain\CareSubscription\CarePlanFrequency;
use App\Domain\CareSubscription\CareSubscriptionAuditActions;
use App\Domain\CareSubscription\Models\CarePlan;
use App\Domain\CareSubscription\Models\SubscriptionCycle;
use App\Domain\CareSubscription\SubscriptionStatus;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class CreateSubscriptionTest extends TestCase
{
    use RefreshDatabase;

    private function makeCarePlan(CarePlanFrequency $frequency = CarePlanFrequency::Monthly): CarePlan
    {
        return CarePlan::query()->create([
            'reference' => 'CP-'.Str::upper(Str::random(8)),
            'name' => 'Test Plan',
            'product_code' => 'GRAVE_CARE_MONTHLY',
            'frequency' => $frequency->value,
            'price_minor' => 150000,
            'currency' => 'IDR',
            'checklist_template' => [
                ['name' => 'Clean grave', 'required_evidence' => true, 'sort_order' => 1],
            ],
            'status' => 'active',
        ]);
    }

    public function test_create_subscription_creates_draft_with_first_cycle(): void
    {
        $plan = $this->makeCarePlan();

        $subscription = app(CreateSubscription::class)(
            $plan,
            (string) Str::uuid(),
            User::factory()->create()->id,
            CarePlanFrequency::Monthly,
            'user:1',
            'admin',
        );

        $this->assertSame(SubscriptionStatus::Draft, SubscriptionStatus::from($subscription->status));
        $this->assertStringStartsWith('SUB-', $subscription->reference);
        $this->assertSame($plan->getKey(), $subscription->care_plan_id);
        $this->assertSame(0, $subscription->current_cycle_number);
        $this->assertSame(150000, $subscription->price_minor);

        $cycles = SubscriptionCycle::query()
            ->where('subscription_id', $subscription->getKey())
            ->get();

        $this->assertCount(1, $cycles);
        $this->assertSame('SCHEDULED', $cycles->first()->status);

        $this->assertDatabaseHas('audit_events', [
            'action' => CareSubscriptionAuditActions::SUBSCRIPTION_CREATED,
            'subject_type' => 'subscription',
            'subject_id' => (string) $subscription->getKey(),
            'actor_ref' => 'user:1',
            'actor_role' => 'admin',
            'outcome' => 'allowed',
        ]);
    }

    public function test_create_subscription_generates_correct_cycle_dates_for_quarterly(): void
    {
        $plan = $this->makeCarePlan(CarePlanFrequency::Quarterly);

        $subscription = app(CreateSubscription::class)(
            $plan,
            (string) Str::uuid(),
            User::factory()->create()->id,
            CarePlanFrequency::Quarterly,
            'user:1',
            'admin',
        );

        $cycle = SubscriptionCycle::query()
            ->where('subscription_id', $subscription->getKey())
            ->first();

        $this->assertNotNull($cycle);
        $start = CarbonImmutable::parse($cycle->cycle_start);
        $end = CarbonImmutable::parse($cycle->cycle_end);
        $this->assertTrue($start->addMonths(3)->subDay()->isSameDay($end));
    }

    public function test_create_subscription_generates_correct_cycle_dates_for_annual(): void
    {
        $plan = $this->makeCarePlan(CarePlanFrequency::Annual);

        $subscription = app(CreateSubscription::class)(
            $plan,
            (string) Str::uuid(),
            User::factory()->create()->id,
            CarePlanFrequency::Annual,
            'user:1',
            'admin',
        );

        $cycle = SubscriptionCycle::query()
            ->where('subscription_id', $subscription->getKey())
            ->first();

        $this->assertNotNull($cycle);
        $start = CarbonImmutable::parse($cycle->cycle_start);
        $end = CarbonImmutable::parse($cycle->cycle_end);
        $this->assertTrue($start->addYear()->subDay()->isSameDay($end));
    }
}
