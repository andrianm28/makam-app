<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\VendorFulfillment;

use App\Domain\CareSubscription\Models\CarePlan;
use App\Domain\CareSubscription\Models\Subscription;
use App\Domain\CareSubscription\Models\SubscriptionCycle;
use App\Domain\VendorFulfillment\Actions\CreateWorkOrderFromCycle;
use App\Domain\VendorFulfillment\Models\WorkOrder;
use App\Domain\VendorFulfillment\Models\WorkOrderTask;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Tests for `CreateWorkOrderFromCycle` — happy path from paid cycle,
 * idempotency (one work order per cycle), and checklist expansion.
 */
final class CreateWorkOrderTest extends TestCase
{
    use RefreshDatabase;

    private function makeCarePlan(array $checklistTemplate = []): CarePlan
    {
        return CarePlan::query()->create([
            'reference' => 'CP-'.Str::upper(Str::random(8)),
            'name' => 'Basic Grave Care',
            'frequency' => 'monthly',
            'price_minor' => 150000,
            'product_code' => 'GC-MONTHLY',
            'status' => 'active',
            'checklist_template' => $checklistTemplate,
        ]);
    }

    private function makeCycle(CarePlan $carePlan): SubscriptionCycle
    {
        $subscription = Subscription::query()->create([
            'reference' => 'SUB-'.Str::upper(Str::random(8)),
            'grave_id' => (string) Str::uuid(),
            'care_plan_id' => $carePlan->getKey(),
            'customer_id' => (string) Str::uuid(),
            'status' => 'active',
            'frequency' => 'monthly',
            'price_minor' => 150000,
            'currency' => 'IDR',
        ]);

        return SubscriptionCycle::query()->create([
            'subscription_id' => $subscription->getKey(),
            'cycle_start' => now()->subMonth(),
            'cycle_end' => now(),
            'status' => 'PAID',
        ]);
    }

    public function test_creates_work_order_in_pending_status_with_audit(): void
    {
        $carePlan = $this->makeCarePlan();
        $cycle = $this->makeCycle($carePlan);

        $workOrder = app(CreateWorkOrderFromCycle::class)($cycle, $carePlan);

        $this->assertSame('pending', $workOrder->status);
        $this->assertSame((string) $carePlan->getKey(), $workOrder->care_plan_id);
        $this->assertSame((string) $cycle->getKey(), $workOrder->subscription_cycle_id);
        $this->assertStringStartsWith('WO-', $workOrder->reference);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'WORK_ORDER_CREATED',
            'subject_type' => 'work_order',
            'subject_id' => (string) $workOrder->getKey(),
        ]);
        $this->assertDatabaseHas('outbox_events', [
            'event_name' => 'care.work_order_created.v1',
            'event_version' => 1,
            'aggregate_type' => 'work_order',
            'aggregate_id' => (string) $workOrder->getKey(),
            'classification' => 'INTERNAL',
            'idempotency_key' => "work_order_created:{$workOrder->getKey()}",
        ]);
    }

    public function test_is_idempotent_for_same_cycle(): void
    {
        $carePlan = $this->makeCarePlan();
        $cycle = $this->makeCycle($carePlan);

        $first = app(CreateWorkOrderFromCycle::class)($cycle, $carePlan);
        $second = app(CreateWorkOrderFromCycle::class)($cycle, $carePlan);

        $this->assertSame((string) $first->getKey(), (string) $second->getKey());
        $this->assertSame(1, WorkOrder::query()->count());
    }

    public function test_expands_checklist_template_into_work_order_tasks(): void
    {
        $checklist = [
            ['name' => 'Clean headstone', 'required_evidence' => true],
            ['name' => 'Trim grass', 'required_evidence' => false],
            ['name' => 'Place flowers', 'required_evidence' => false],
        ];
        $carePlan = $this->makeCarePlan($checklist);
        $cycle = $this->makeCycle($carePlan);

        $workOrder = app(CreateWorkOrderFromCycle::class)($cycle, $carePlan);

        $tasks = WorkOrderTask::query()
            ->where('work_order_id', $workOrder->getKey())
            ->orderBy('sort_order')
            ->get();

        $this->assertCount(3, $tasks);
        $this->assertSame('Clean headstone', $tasks[0]->name);
        $this->assertTrue((bool) $tasks[0]->required_evidence);
        $this->assertSame(1, $tasks[0]->sort_order);
        $this->assertSame('Trim grass', $tasks[1]->name);
        $this->assertFalse((bool) $tasks[1]->required_evidence);
        $this->assertSame('Place flowers', $tasks[2]->name);
        $this->assertSame(3, $tasks[2]->sort_order);
    }

    public function test_empty_checklist_template_creates_no_tasks(): void
    {
        $carePlan = $this->makeCarePlan([]);
        $cycle = $this->makeCycle($carePlan);

        $workOrder = app(CreateWorkOrderFromCycle::class)($cycle, $carePlan);

        $this->assertSame(0, WorkOrderTask::query()
            ->where('work_order_id', $workOrder->getKey())
            ->count()
        );
    }

    public function test_creates_separate_work_orders_for_different_cycles(): void
    {
        $carePlan = $this->makeCarePlan();
        $cycle1 = $this->makeCycle($carePlan);
        $cycle2 = $this->makeCycle($carePlan);

        $wo1 = app(CreateWorkOrderFromCycle::class)($cycle1, $carePlan);
        $wo2 = app(CreateWorkOrderFromCycle::class)($cycle2, $carePlan);

        $this->assertNotSame((string) $wo1->getKey(), (string) $wo2->getKey());
        $this->assertSame(2, WorkOrder::query()->count());
    }
}
