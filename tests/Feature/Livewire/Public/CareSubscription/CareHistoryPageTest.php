<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Public\CareSubscription;

use App\Domain\CareSubscription\CarePlanFrequency;
use App\Domain\CareSubscription\Models\CarePlan;
use App\Domain\CareSubscription\Models\Subscription;
use App\Domain\CareSubscription\Models\SubscriptionCycle;
use App\Domain\VendorFulfillment\WorkOrderStatus;
use App\Domain\VendorFulfillment\Models\WorkOrder;
use App\Livewire\Public\CareSubscription\CareHistoryPage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * CareHistoryPage (P5b Lane 4) — `/riwayat-perawatan/{customerId}`:
 * renders work order history with SEPARATE billing + work status badges
 * (AC2/AC6). PAID ≠ COMPLETED. Missed/failed service shows honest
 * operational state, never styled as customer error (AC6). Server-confirmed
 * state only (AC4).
 */
final class CareHistoryPageTest extends TestCase
{
    use RefreshDatabase;

    private string $customerId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->customerId = (string) Str::uuid();
    }

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

    private function createSubscription(): Subscription
    {
        $carePlan = $this->createCarePlan();

        return Subscription::query()->create([
            'reference' => 'SUB-'.Str::upper(Str::random(8)),
            'grave_id' => (string) Str::uuid(),
            'care_plan_id' => $carePlan->getKey(),
            'customer_id' => $this->customerId,
            'status' => 'active',
            'frequency' => CarePlanFrequency::Monthly->value,
            'price_minor' => 150000,
            'currency' => 'IDR',
            'current_cycle_number' => 2,
            'started_at' => now()->subMonths(2),
        ]);
    }

    private function createWorkOrderForCycle(SubscriptionCycle $cycle, string $workStatus = 'COMPLETED'): WorkOrder
    {
        return WorkOrder::query()->create([
            'reference' => 'WO-'.Str::upper(Str::random(8)),
            'care_plan_id' => $cycle->subscription_id,
            'subscription_cycle_id' => $cycle->getKey(),
            'status' => $workStatus,
        ]);
    }

    public function test_the_care_history_page_renders_for_a_valid_customer_id(): void
    {
        $subscription = $this->createSubscription();

        $cycle = SubscriptionCycle::query()->create([
            'subscription_id' => $subscription->getKey(),
            'cycle_start' => now()->subMonth()->startOfMonth()->toDateString(),
            'cycle_end' => now()->subMonth()->endOfMonth()->toDateString(),
            'status' => 'COMPLETED',
        ]);

        $this->createWorkOrderForCycle($cycle, 'COMPLETED');

        Livewire::test(CareHistoryPage::class, [
            'customerId' => $this->customerId,
        ])
            ->assertOk()
            ->assertSee('Riwayat Perawatan')
            ->assertSee('Completed');
    }

    public function test_the_care_history_page_shows_separate_billing_and_work_badges(): void
    {
        $subscription = $this->createSubscription();

        $cycle = SubscriptionCycle::query()->create([
            'subscription_id' => $subscription->getKey(),
            'cycle_start' => now()->subMonth()->startOfMonth()->toDateString(),
            'cycle_end' => now()->subMonth()->endOfMonth()->toDateString(),
            'status' => 'PAID',
        ]);

        $this->createWorkOrderForCycle($cycle, 'IN_PROGRESS');

        $html = Livewire::test(CareHistoryPage::class, [
            'customerId' => $this->customerId,
        ])
            ->assertOk()
            ->html();

        // Billing and work order are TWO SEPARATE indicators (AC2/AC6)
        $this->assertStringContainsString('Paid', $html);
        $this->assertStringContainsString('In progress', $html);

        // They are never collapsed into one badge — both labels exist
        $this->assertStringContainsString('Status Pembayaran', $html);
        $this->assertStringContainsString('Status Pekerjaan', $html);
    }

    public function test_the_care_history_page_shows_empty_state_when_no_work_orders(): void
    {
        Livewire::test(CareHistoryPage::class, [
            'customerId' => $this->customerId,
        ])
            ->assertOk()
            ->assertSee('Belum ada riwayat perawatan');
    }

    public function test_the_care_history_page_shares_honest_state_for_failed_service(): void
    {
        $subscription = $this->createSubscription();

        $cycle = SubscriptionCycle::query()->create([
            'subscription_id' => $subscription->getKey(),
            'cycle_start' => now()->subMonth()->startOfMonth()->toDateString(),
            'cycle_end' => now()->subMonth()->endOfMonth()->toDateString(),
            'status' => 'PAID',
        ]);

        $this->createWorkOrderForCycle($cycle, 'FAILED');

        Livewire::test(CareHistoryPage::class, [
            'customerId' => $this->customerId,
        ])
            ->assertOk()
            ->assertSee('Pekerjaan belum terselesaikan')
            ->assertSee('Failed');
    }

    public function test_paid_is_never_shown_as_completed(): void
    {
        $subscription = $this->createSubscription();

        $cycle = SubscriptionCycle::query()->create([
            'subscription_id' => $subscription->getKey(),
            'cycle_start' => now()->subMonth()->startOfMonth()->toDateString(),
            'cycle_end' => now()->subMonth()->endOfMonth()->toDateString(),
            'status' => 'PAID',
        ]);

        $this->createWorkOrderForCycle($cycle, 'PENDING');

        $html = Livewire::test(CareHistoryPage::class, [
            'customerId' => $this->customerId,
        ])
            ->assertOk()
            ->html();

        // PAID billing badge shows "Paid", NOT "Completed"
        $this->assertStringContainsString('Paid', $html);
        // Work order shows "Pending", NOT "Completed"
        $this->assertStringContainsString('Pending', $html);
    }
}
