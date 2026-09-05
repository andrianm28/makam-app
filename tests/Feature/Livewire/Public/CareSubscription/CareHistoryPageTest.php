<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Public\CareSubscription;

use App\Domain\CareSubscription\CarePlanFrequency;
use App\Domain\CareSubscription\Models\CarePlan;
use App\Domain\CareSubscription\Models\Subscription;
use App\Domain\CareSubscription\Models\SubscriptionCycle;
use App\Domain\VendorFulfillment\Models\WorkOrder;
use App\Livewire\Public\CareSubscription\CareHistoryPage;
use App\Models\User;
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

        // `subscriptions.customer_id` is a real bigint FK to `users` (fixed
        // 22 Aug 2026) -- a random uuid is no longer a valid fixture value.
        $customer = User::factory()->create();
        $this->customerId = (string) $customer->id;

        // Every test below asserts the page's REAL content for its own
        // customer -- since the IDOR fix (5 Sep 2026), resolveWorkOrders()
        // returns nothing at all unless the viewer IS this customer (see
        // CareHistoryPage's own class doc block), so every content
        // assertion needs a real authenticated actor now, not just a
        // customerId prop.
        $this->actingAs($customer);
    }

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

    private function createWorkOrderForCycle(SubscriptionCycle $cycle, string $workStatus = 'completed'): WorkOrder
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

        $this->createWorkOrderForCycle($cycle, 'completed');

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

        $this->createWorkOrderForCycle($cycle, 'in_progress');

        $html = Livewire::test(CareHistoryPage::class, [
            'customerId' => $this->customerId,
        ])
            ->assertOk()
            ->html();

        // Billing and work order are TWO SEPARATE indicators (AC2/AC6)
        $this->assertStringContainsString('Paid', $html);
        $this->assertStringContainsString('In Progress', $html);

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

        $this->createWorkOrderForCycle($cycle, 'missed');

        Livewire::test(CareHistoryPage::class, [
            'customerId' => $this->customerId,
        ])
            ->assertOk()
            ->assertSee('Pekerjaan belum terselesaikan')
            ->assertSee('Missed');
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

        $this->createWorkOrderForCycle($cycle, 'pending');

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

    /**
     * The real, live cross-customer IDOR this fix closes: before 5 Sep
     * 2026, `resolveWorkOrders()` trusted the raw `customerId` route
     * segment directly, so any authenticated customer could view another
     * customer's full work-order/billing/complaint history by visiting
     * `/riwayat-perawatan/{someone_elses_id}`. Same test shape as
     * `App\Livewire\Public\Memorial\MemorialFamilyPageTest`'s own
     * cross-family denial test: the wrong viewer gets the SAME uniform
     * "no history" empty state a genuinely history-less customer would see
     * — never a distinguishing 403/404, which would itself leak which
     * customer ids are real.
     */
    public function test_an_authenticated_customer_cannot_see_another_customers_care_history(): void
    {
        // $this->customerId (from setUp) is the VICTIM -- it already has a
        // real subscription with a real, identifiable work order.
        $subscription = $this->createSubscription();

        $cycle = SubscriptionCycle::query()->create([
            'subscription_id' => $subscription->getKey(),
            'cycle_start' => now()->subMonth()->startOfMonth()->toDateString(),
            'cycle_end' => now()->subMonth()->endOfMonth()->toDateString(),
            'status' => 'COMPLETED',
        ]);
        $victimsWorkOrder = $this->createWorkOrderForCycle($cycle, 'completed');

        // A completely unrelated, real, authenticated customer -- the
        // attacker -- logs in as themselves and requests the VICTIM's
        // customerId directly.
        $attacker = User::factory()->create();
        $this->actingAs($attacker);

        Livewire::test(CareHistoryPage::class, [
            'customerId' => $this->customerId,
        ])
            ->assertOk()
            ->assertSee('Belum ada riwayat perawatan')
            ->assertDontSee($victimsWorkOrder->reference);
    }
}
