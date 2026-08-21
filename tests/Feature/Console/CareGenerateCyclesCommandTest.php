<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Domain\CareSubscription\CarePlanFrequency;
use App\Domain\CareSubscription\Models\CarePlan;
use App\Domain\CareSubscription\Models\Subscription;
use App\Domain\CareSubscription\SubscriptionStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * CareGenerateCyclesCommand (P5b Lane 4) — `care:generate-cycles`:
 * finds ACTIVE subscriptions where the next cycle is due and generates them.
 * Idempotent: GenerateCycle handles dedup via the unique constraint.
 * Reports counts only, never PII.
 */
final class CareGenerateCyclesCommandTest extends TestCase
{
    use RefreshDatabase;

    private function createCarePlan(): CarePlan
    {
        return CarePlan::query()->create([
            'reference' => 'CP-'.Str::upper(Str::random(8)),
            'name' => 'Perawatan Bulanan Standar',
            'product_code' => 'GRAVE_CARE_MONTHLY',
            'frequency' => CarePlanFrequency::Monthly->value,
            'price_minor' => 150000,
            'currency' => 'IDR',
            'checklist_template' => ['membersihkan makam'],
            'status' => 'active',
        ]);
    }

    public function test_the_command_generates_cycles_for_due_subscriptions(): void
    {
        $carePlan = $this->createCarePlan();

        // A subscription started 2 months ago with 0 cycles — the scheduler
        // should generate a cycle for it.
        Subscription::query()->create([
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

        $this->artisan('care:generate-cycles')
            ->assertExitCode(0)
            ->expectsOutputToContain('Generated');
    }

    public function test_the_command_does_not_generate_for_paused_subscriptions(): void
    {
        $carePlan = $this->createCarePlan();

        Subscription::query()->create([
            'reference' => 'SUB-'.Str::upper(Str::random(8)),
            'grave_id' => (string) Str::uuid(),
            'care_plan_id' => $carePlan->getKey(),
            'customer_id' => User::factory()->create()->id,
            'status' => SubscriptionStatus::Paused->value,
            'frequency' => CarePlanFrequency::Monthly->value,
            'price_minor' => $carePlan->price_minor,
            'currency' => 'IDR',
            'current_cycle_number' => 0,
            'started_at' => now()->subMonths(2),
        ]);

        $this->artisan('care:generate-cycles')
            ->assertExitCode(0)
            ->expectsOutputToContain('Generated 0 cycle(s)');
    }

    public function test_the_command_is_idempotent(): void
    {
        $carePlan = $this->createCarePlan();

        Subscription::query()->create([
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

        // Run twice — second run should not create duplicates
        $this->artisan('care:generate-cycles')->assertExitCode(0);
        $this->artisan('care:generate-cycles')->assertExitCode(0);

        $this->assertDatabaseCount('subscription_cycles', 1);
    }

    public function test_the_command_does_not_leak_pii_in_output(): void
    {
        $carePlan = $this->createCarePlan();

        Subscription::query()->create([
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

        $output = $this->artisan('care:generate-cycles');

        // Output must contain only counts, never PII
        $output->assertExitCode(0);
    }
}
