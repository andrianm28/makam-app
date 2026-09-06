<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\VendorFulfillment;

use App\Domain\CareSubscription\Models\CarePlan;
use App\Domain\CareSubscription\Models\Subscription;
use App\Domain\CareSubscription\Models\SubscriptionCycle;
use App\Domain\VendorFulfillment\Actions\CreateMakeGood;
use App\Domain\VendorFulfillment\Actions\CreateWorkOrderFromCycle;
use App\Domain\VendorFulfillment\Actions\FileComplaint;
use App\Domain\VendorFulfillment\ComplaintStatus;
use App\Domain\VendorFulfillment\MakeGoodStatus;
use App\Domain\VendorFulfillment\Models\WorkOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Tests for complaint flow: file complaint → OPEN, create make-good →
 * new work order + outbox event.
 */
final class ComplaintFlowTest extends TestCase
{
    use RefreshDatabase;

    private function makeWorkOrder(): WorkOrder
    {
        $carePlan = CarePlan::query()->create([
            'reference' => 'CP-'.Str::upper(Str::random(8)),
            'name' => 'Basic Care',
            'frequency' => 'monthly',
            'price_minor' => 150000,
            'product_code' => 'GC-MONTHLY',
            'status' => 'active',
            'checklist_template' => [],
        ]);
        $subscription = Subscription::query()->create([
            'reference' => 'SUB-'.Str::upper(Str::random(8)),
            'grave_id' => (string) Str::uuid(),
            'care_plan_id' => $carePlan->getKey(),
            'customer_id' => User::factory()->create()->id,
            'status' => 'active',
            'frequency' => 'monthly',
            'price_minor' => 150000,
            'currency' => 'IDR',
        ]);
        $cycle = SubscriptionCycle::query()->create([
            'subscription_id' => $subscription->getKey(),
            'cycle_start' => now()->subMonth(),
            'cycle_end' => now(),
            'status' => 'PAID',
        ]);

        return app(CreateWorkOrderFromCycle::class)($cycle, $carePlan);
    }

    public function test_file_complaint_creates_open_complaint_with_audit_and_outbox(): void
    {
        $workOrder = $this->makeWorkOrder();

        $complaint = app(FileComplaint::class)(
            $workOrder,
            User::factory()->create()->id,
            'Service was not performed properly.',
        );

        $this->assertSame(ComplaintStatus::Open->value, $complaint->status);
        $this->assertSame('Service was not performed properly.', $complaint->complaint_text);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'COMPLAINT_FILED',
            'subject_type' => 'work_order',
            'subject_id' => (string) $workOrder->getKey(),
        ]);
        $this->assertDatabaseHas('outbox_events', [
            'event_name' => 'care.complaint_filed.v1',
            'event_version' => 1,
            'aggregate_type' => 'service_complaint',
            'aggregate_id' => (string) $complaint->getKey(),
            'classification' => 'INTERNAL',
            'idempotency_key' => "complaint_filed:{$complaint->getKey()}",
        ]);
    }

    public function test_make_good_creates_replacement_work_order_and_audit(): void
    {
        $workOrder = $this->makeWorkOrder();

        $makeGood = app(CreateMakeGood::class)($workOrder, 'Headstone not cleaned');

        $this->assertSame(MakeGoodStatus::Pending->value, $makeGood->status);
        $this->assertSame((string) $workOrder->getKey(), $makeGood->original_work_order_id);
        $this->assertNotNull($makeGood->replacement_work_order_id);
        $this->assertNotSame(
            (string) $workOrder->getKey(),
            (string) $makeGood->replacement_work_order_id,
        );
        $this->assertSame('Headstone not cleaned', $makeGood->notes);

        $replacement = WorkOrder::query()->find($makeGood->replacement_work_order_id);
        $this->assertNotNull($replacement);
        $this->assertSame('pending', $replacement->status);
        $this->assertSame(
            (string) $workOrder->care_plan_id,
            (string) $replacement->care_plan_id,
        );
        $this->assertSame(
            (string) $workOrder->subscription_cycle_id,
            (string) $replacement->subscription_cycle_id,
        );

        $this->assertDatabaseHas('audit_events', [
            'action' => 'MAKE_GOOD_CREATED',
            'subject_type' => 'work_order',
            'subject_id' => (string) $workOrder->getKey(),
        ]);
        $this->assertDatabaseHas('outbox_events', [
            'event_name' => 'care.make_good_created.v1',
            'event_version' => 1,
            'aggregate_type' => 'make_good_order',
            'aggregate_id' => (string) $makeGood->getKey(),
            'classification' => 'INTERNAL',
            'idempotency_key' => "make_good_created:{$makeGood->getKey()}",
        ]);
    }

    public function test_full_complaint_to_make_good_flow(): void
    {
        $workOrder = $this->makeWorkOrder();

        $complaint = app(FileComplaint::class)(
            $workOrder,
            User::factory()->create()->id,
            'Grass was not trimmed.',
        );
        $this->assertSame(ComplaintStatus::Open->value, $complaint->status);

        $makeGood = app(CreateMakeGood::class)($workOrder);
        $this->assertSame(MakeGoodStatus::Pending->value, $makeGood->status);
        $this->assertSame(
            (string) $workOrder->getKey(),
            (string) $makeGood->original_work_order_id,
        );

        $this->assertDatabaseHas('audit_events', [
            'action' => 'COMPLAINT_FILED',
            'subject_id' => (string) $workOrder->getKey(),
        ]);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'MAKE_GOOD_CREATED',
            'subject_id' => (string) $workOrder->getKey(),
        ]);
    }

    public function test_service_complaint_has_a_nullable_make_good_order_id_column_and_real_relations(): void
    {
        $workOrder = $this->makeWorkOrder();

        $complaint = app(FileComplaint::class)(
            $workOrder,
            User::factory()->create()->id,
            'Grass was not trimmed.',
        );

        $this->assertNull($complaint->make_good_order_id);
        $this->assertTrue($complaint->workOrder()->exists());
        $this->assertSame((string) $workOrder->getKey(), (string) $complaint->workOrder->getKey());
        $this->assertTrue($complaint->customer()->exists());

        $makeGood = app(CreateMakeGood::class)($workOrder);

        $complaint->forceFill(['make_good_order_id' => $makeGood->getKey()])->save();

        $this->assertSame((string) $makeGood->getKey(), (string) $complaint->fresh()->make_good_order_id);
    }
}
