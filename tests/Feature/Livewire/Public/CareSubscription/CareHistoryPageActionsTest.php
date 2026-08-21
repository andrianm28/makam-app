<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Public\CareSubscription;

use App\Domain\CareSubscription\CarePlanFrequency;
use App\Domain\CareSubscription\Models\CarePlan;
use App\Domain\CareSubscription\Models\Subscription;
use App\Domain\CareSubscription\Models\SubscriptionCycle;
use App\Domain\VendorFulfillment\Models\ServiceAcceptance;
use App\Domain\VendorFulfillment\Models\WorkOrder;
use App\Domain\VendorFulfillment\WorkOrderStatus;
use App\Livewire\Public\CareSubscription\CareHistoryPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * `CareHistoryPage`'s write surface — accept/file-complaint (P5b Lane 4
 * wiring gap: the page existed as a 100% read-only view; this batch adds
 * `acceptService()`/`fileComplaint()`). Proves: only the authenticated
 * customer that owns the history may act; a completed-only gate on
 * acceptance; no double acceptance; the IDOR backstop
 * (`ownedWorkOrder()`) refuses a work order id belonging to a different
 * customer even when supplied directly via a wire call.
 */
/**
 * UNBLOCKED 22 Aug 2026 (`2026_08_22_100000_fix_customer_and_uploader_
 * identity_columns`): `subscriptions.customer_id` was re-typed from `uuid`
 * to a real `foreignId(...)->constrained('users')`, matching
 * `App\Domain\CareSubscription\Actions\CreateSubscription`'s corrected
 * `int $customerId` signature. `makeSubscriptionAndCycle((string) $customer->id)`
 * below now builds a genuinely valid fixture — a real bigint FK to a real
 * `users` row — on both SQLite and real Postgres, so this class no longer
 * needs to skip.
 */
final class CareHistoryPageActionsTest extends TestCase
{
    use RefreshDatabase;

    private function makeSubscriptionAndCycle(string $customerId, string $cycleStatus = 'PAID'): SubscriptionCycle
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

        $subscription = Subscription::query()->create([
            'reference' => 'SUB-'.Str::upper(Str::random(8)),
            'grave_id' => (string) Str::uuid(),
            'care_plan_id' => $carePlan->getKey(),
            'customer_id' => $customerId,
            'status' => 'active',
            'frequency' => CarePlanFrequency::Monthly->value,
            'price_minor' => 150000,
            'currency' => 'IDR',
        ]);

        return SubscriptionCycle::query()->create([
            'subscription_id' => $subscription->getKey(),
            'cycle_start' => now()->subMonth()->startOfMonth()->toDateString(),
            'cycle_end' => now()->subMonth()->endOfMonth()->toDateString(),
            'status' => $cycleStatus,
        ]);
    }

    private function makeWorkOrder(SubscriptionCycle $cycle, string $status = WorkOrderStatus::Completed->value): WorkOrder
    {
        $subscription = Subscription::query()->findOrFail($cycle->subscription_id);

        return WorkOrder::query()->create([
            'reference' => 'WO-'.Str::upper(Str::random(8)),
            'care_plan_id' => $subscription->care_plan_id,
            'subscription_cycle_id' => $cycle->getKey(),
            'status' => $status,
        ]);
    }

    public function test_a_guest_cannot_accept_a_service(): void
    {
        $customer = User::factory()->create();
        $cycle = $this->makeSubscriptionAndCycle((string) $customer->id);
        $workOrder = $this->makeWorkOrder($cycle);

        Livewire::test(CareHistoryPage::class, ['customerId' => (string) $customer->id])
            ->set('expandedWorkOrderId', $workOrder->getKey())
            ->call('acceptService')
            ->assertHasErrors(['action']);

        $this->assertDatabaseCount('service_acceptances', 0);
    }

    public function test_an_authenticated_visitor_cannot_act_on_someone_elses_history(): void
    {
        $customer = User::factory()->create();
        $otherVisitor = User::factory()->create();
        $cycle = $this->makeSubscriptionAndCycle((string) $customer->id);
        $workOrder = $this->makeWorkOrder($cycle);

        $this->actingAs($otherVisitor);

        Livewire::test(CareHistoryPage::class, ['customerId' => (string) $customer->id])
            ->set('expandedWorkOrderId', $workOrder->getKey())
            ->call('acceptService')
            ->assertHasErrors(['action']);

        $this->assertDatabaseCount('service_acceptances', 0);
    }

    public function test_the_owning_customer_accepts_a_completed_service(): void
    {
        $customer = User::factory()->create();
        $cycle = $this->makeSubscriptionAndCycle((string) $customer->id);
        $workOrder = $this->makeWorkOrder($cycle, WorkOrderStatus::Completed->value);

        $this->actingAs($customer);

        Livewire::test(CareHistoryPage::class, ['customerId' => (string) $customer->id])
            ->call('showAcceptForm', $workOrder->getKey())
            ->set('rating', '5')
            ->set('acceptanceNotes', 'Rapi dan tepat waktu.')
            ->call('acceptService')
            ->assertHasNoErrors()
            ->assertSee('Terima kasih, penerimaan layanan telah dicatat.');

        $this->assertDatabaseHas('service_acceptances', [
            'work_order_id' => $workOrder->getKey(),
            'customer_id' => (string) $customer->id,
            'rating' => 5,
            'notes' => 'Rapi dan tepat waktu.',
        ]);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'SERVICE_ACCEPTED',
            'subject_id' => (string) $workOrder->getKey(),
            'actor_role' => 'customer',
        ]);
    }

    public function test_accepting_an_incomplete_service_is_refused_with_an_honest_error(): void
    {
        $customer = User::factory()->create();
        $cycle = $this->makeSubscriptionAndCycle((string) $customer->id);
        $workOrder = $this->makeWorkOrder($cycle, WorkOrderStatus::InProgress->value);

        $this->actingAs($customer);

        Livewire::test(CareHistoryPage::class, ['customerId' => (string) $customer->id])
            ->call('showAcceptForm', $workOrder->getKey())
            ->call('acceptService')
            ->assertHasErrors(['action']);

        $this->assertDatabaseCount('service_acceptances', 0);
    }

    public function test_the_owning_customer_cannot_accept_the_same_service_twice(): void
    {
        $customer = User::factory()->create();
        $cycle = $this->makeSubscriptionAndCycle((string) $customer->id);
        $workOrder = $this->makeWorkOrder($cycle, WorkOrderStatus::Completed->value);

        $this->actingAs($customer);

        Livewire::test(CareHistoryPage::class, ['customerId' => (string) $customer->id])
            ->call('showAcceptForm', $workOrder->getKey())
            ->call('acceptService')
            ->assertHasNoErrors();

        Livewire::test(CareHistoryPage::class, ['customerId' => (string) $customer->id])
            ->call('showAcceptForm', $workOrder->getKey())
            ->call('acceptService')
            ->assertHasErrors(['action']);

        $this->assertSame(1, ServiceAcceptance::query()->count());
    }

    public function test_the_owning_customer_files_a_complaint(): void
    {
        $customer = User::factory()->create();
        $cycle = $this->makeSubscriptionAndCycle((string) $customer->id);
        $workOrder = $this->makeWorkOrder($cycle, WorkOrderStatus::Missed->value);

        $this->actingAs($customer);

        Livewire::test(CareHistoryPage::class, ['customerId' => (string) $customer->id])
            ->call('showComplaintForm', $workOrder->getKey())
            ->set('complaintText', 'Pekerjaan tidak dilakukan sesuai jadwal.')
            ->call('fileComplaint')
            ->assertHasNoErrors()
            ->assertSee('Komplain Anda telah dikirim.');

        $this->assertDatabaseHas('service_complaints', [
            'work_order_id' => $workOrder->getKey(),
            'customer_id' => (string) $customer->id,
            'complaint_text' => 'Pekerjaan tidak dilakukan sesuai jadwal.',
            'status' => 'OPEN',
        ]);
        $this->assertDatabaseHas('outbox_events', [
            'event_name' => 'care.complaint_filed.v1',
        ]);
    }

    public function test_filing_a_complaint_requires_at_least_ten_characters(): void
    {
        $customer = User::factory()->create();
        $cycle = $this->makeSubscriptionAndCycle((string) $customer->id);
        $workOrder = $this->makeWorkOrder($cycle, WorkOrderStatus::Missed->value);

        $this->actingAs($customer);

        Livewire::test(CareHistoryPage::class, ['customerId' => (string) $customer->id])
            ->call('showComplaintForm', $workOrder->getKey())
            ->set('complaintText', 'pendek')
            ->call('fileComplaint');

        $this->assertDatabaseCount('service_complaints', 0);
    }

    /**
     * The IDOR backstop: `expandedWorkOrderId` is a plain public Livewire
     * property, settable directly from the client. Even when the visitor
     * IS authenticated as the customer whose page this is, a work order
     * belonging to a DIFFERENT customer's subscription must be refused —
     * `ownedWorkOrder()` re-derives ownership through the same
     * subscription join `resolveWorkOrders()` uses, independent of which
     * work orders happen to be rendered in the list.
     */
    public function test_a_work_order_belonging_to_another_customer_cannot_be_accepted_via_a_direct_wire_call(): void
    {
        $customer = User::factory()->create();
        $otherCustomer = User::factory()->create();

        $ownCycle = $this->makeSubscriptionAndCycle((string) $customer->id);
        $this->makeWorkOrder($ownCycle, WorkOrderStatus::Completed->value);

        $foreignCycle = $this->makeSubscriptionAndCycle((string) $otherCustomer->id);
        $foreignWorkOrder = $this->makeWorkOrder($foreignCycle, WorkOrderStatus::Completed->value);

        $this->actingAs($customer);

        Livewire::test(CareHistoryPage::class, ['customerId' => (string) $customer->id])
            ->set('expandedWorkOrderId', $foreignWorkOrder->getKey())
            ->call('acceptService')
            ->assertHasErrors(['action']);

        $this->assertDatabaseCount('service_acceptances', 0);
    }
}
