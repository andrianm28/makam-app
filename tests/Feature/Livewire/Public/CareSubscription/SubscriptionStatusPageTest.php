<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Public\CareSubscription;

use App\Domain\CareSubscription\CarePlanFrequency;
use App\Domain\CareSubscription\Models\CarePlan;
use App\Domain\CareSubscription\Models\Subscription;
use App\Domain\CareSubscription\Models\SubscriptionCycle;
use App\Livewire\Public\CareSubscription\SubscriptionStatusPage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * SubscriptionStatusPage (P5b Lane 4) — `/langganan/{subscriptionReference}`:
 * renders subscription status, care plan name, frequency, price, and cycle
 * history with SEPARATE billing + work order status badges (AC6). PAID ≠
 * COMPLETED. Server-confirmed state only (AC4). No vault references or
 * subject internals leak through this route.
 */
final class SubscriptionStatusPageTest extends TestCase
{
    use RefreshDatabase;

    private function createCarePlan(): CarePlan
    {
        return CarePlan::query()->create([
            'reference' => 'CP-'.Str::upper(Str::random(8)),
            'name' => 'Perawatan Bulanan Standar',
            'frequency' => CarePlanFrequency::Monthly->value,
            'price_minor' => 150000,
            'currency' => 'IDR',
            'checklist_template' => ['membersihkan makam'],
            'status' => 'active',
        ]);
    }

    private function createSubscription(CarePlan $carePlan, string $status = 'active'): Subscription
    {
        return Subscription::query()->create([
            'reference' => 'SUB-'.Str::upper(Str::random(8)),
            'grave_id' => (string) Str::uuid(),
            'care_plan_id' => $carePlan->getKey(),
            'customer_id' => (string) Str::uuid(),
            'status' => $status,
            'frequency' => CarePlanFrequency::Monthly->value,
            'price_minor' => $carePlan->price_minor,
            'currency' => 'IDR',
            'current_cycle_number' => 2,
            'started_at' => now()->subMonths(2),
        ]);
    }

    public function test_the_subscription_status_page_renders_for_a_valid_reference(): void
    {
        $carePlan = $this->createCarePlan();
        $subscription = $this->createSubscription($carePlan);

        Livewire::test(SubscriptionStatusPage::class, [
            'subscriptionReference' => $subscription->reference,
        ])
            ->assertOk()
            ->assertSee($subscription->reference)
            ->assertSee('Perawatan Bulanan Standar')
            ->assertSee('Bulanan')
            ->assertSee('150.000');
    }

    public function test_the_subscription_status_page_shows_the_subscription_status_badge(): void
    {
        $carePlan = $this->createCarePlan();
        $subscription = $this->createSubscription($carePlan, 'active');

        Livewire::test(SubscriptionStatusPage::class, [
            'subscriptionReference' => $subscription->reference,
        ])
            ->assertOk()
            ->assertSee('Active');
    }

    public function test_the_subscription_status_page_renders_cycle_history(): void
    {
        $carePlan = $this->createCarePlan();
        $subscription = $this->createSubscription($carePlan);

        SubscriptionCycle::query()->create([
            'subscription_id' => $subscription->getKey(),
            'cycle_start' => now()->subMonth()->startOfMonth()->toDateString(),
            'cycle_end' => now()->subMonth()->endOfMonth()->toDateString(),
            'status' => 'COMPLETED',
        ]);

        Livewire::test(SubscriptionStatusPage::class, [
            'subscriptionReference' => $subscription->reference,
        ])
            ->assertOk()
            ->assertSee('Riwayat Siklus')
            ->assertSee('Completed');
    }

    public function test_the_subscription_status_page_404s_on_an_unknown_reference(): void
    {
        $this->get(route('langganan.status', [
            'subscriptionReference' => 'SUB-UNKNOWN',
        ]))->assertNotFound();
    }

    public function test_the_subscription_status_page_does_not_reveal_vault_references(): void
    {
        $carePlan = $this->createCarePlan();
        $subscription = $this->createSubscription($carePlan);

        $html = Livewire::test(SubscriptionStatusPage::class, [
            'subscriptionReference' => $subscription->reference,
        ])
            ->assertOk()
            ->html();

        // No vault document references or subject internals leak
        $this->assertStringNotContainsString('document_id', $html);
        $this->assertStringNotContainsString('storage_key', $html);
        $this->assertStringNotContainsString((string) $subscription->grave_id, $html);
        $this->assertStringNotContainsString((string) $subscription->customer_id, $html);
    }

    public function test_the_subscription_status_page_shows_empty_state_when_no_cycles(): void
    {
        $carePlan = $this->createCarePlan();
        $subscription = $this->createSubscription($carePlan);

        Livewire::test(SubscriptionStatusPage::class, [
            'subscriptionReference' => $subscription->reference,
        ])
            ->assertOk()
            ->assertSee('Belum ada riwayat siklus');
    }

    public function test_the_subscription_status_page_renders_paused_status(): void
    {
        $carePlan = $this->createCarePlan();
        $subscription = $this->createSubscription($carePlan, 'paused');

        Livewire::test(SubscriptionStatusPage::class, [
            'subscriptionReference' => $subscription->reference,
        ])
            ->assertOk()
            ->assertSee('Paused');
    }
}
