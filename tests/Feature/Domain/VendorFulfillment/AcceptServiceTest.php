<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\VendorFulfillment;

use App\Domain\CareSubscription\Models\CarePlan;
use App\Domain\VendorFulfillment\Actions\AcceptService;
use App\Domain\VendorFulfillment\Models\ServiceAcceptance;
use App\Domain\VendorFulfillment\Models\WorkOrder;
use App\Domain\VendorFulfillment\VendorFulfillmentAuditActions;
use App\Domain\VendorFulfillment\WorkOrderStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Tests for `AcceptService` — customer acceptance of a completed work order,
 * with an optional 1-5 satisfaction rating.
 */
final class AcceptServiceTest extends TestCase
{
    use RefreshDatabase;

    private function makeCompletedWorkOrder(): WorkOrder
    {
        $carePlan = CarePlan::query()->create([
            'reference' => 'CP-'.Str::upper(Str::random(8)),
            'name' => 'Basic Grave Care',
            'frequency' => 'monthly',
            'price_minor' => 150000,
            'product_code' => 'GC-MONTHLY',
            'status' => 'active',
            'checklist_template' => [],
        ]);

        return WorkOrder::query()->create([
            'reference' => 'WO-'.Str::upper(Str::random(8)),
            'care_plan_id' => $carePlan->getKey(),
            'status' => WorkOrderStatus::Completed->value,
            'completed_at' => now(),
        ]);
    }

    public function test_accepts_a_completed_work_order_with_a_rating(): void
    {
        $workOrder = $this->makeCompletedWorkOrder();
        $customerId = (string) Str::uuid();

        $acceptance = app(AcceptService::class)($workOrder, $customerId, 5, 'Great service, thank you.');

        $this->assertInstanceOf(ServiceAcceptance::class, $acceptance);
        $this->assertSame((string) $workOrder->getKey(), $acceptance->work_order_id);
        $this->assertSame($customerId, $acceptance->customer_id);
        $this->assertSame(5, $acceptance->rating);
        $this->assertSame('Great service, thank you.', $acceptance->notes);
        $this->assertNotNull($acceptance->accepted_at);

        $this->assertDatabaseHas('service_acceptances', [
            'work_order_id' => $workOrder->getKey(),
            'customer_id' => $customerId,
            'rating' => 5,
        ]);

        $this->assertDatabaseHas('audit_events', [
            'action' => VendorFulfillmentAuditActions::SERVICE_ACCEPTED,
            'subject_type' => 'work_order',
            'subject_id' => (string) $workOrder->getKey(),
            'actor_ref' => $customerId,
            'actor_role' => 'customer',
            'outcome' => 'allowed',
        ]);
    }

    public function test_accepts_a_completed_work_order_with_no_rating_or_notes(): void
    {
        $workOrder = $this->makeCompletedWorkOrder();
        $customerId = (string) Str::uuid();

        $acceptance = app(AcceptService::class)($workOrder, $customerId, null, null);

        $this->assertNull($acceptance->rating);
        $this->assertNull($acceptance->notes);
        $this->assertDatabaseHas('service_acceptances', [
            'work_order_id' => $workOrder->getKey(),
            'customer_id' => $customerId,
            'rating' => null,
        ]);
    }

    public function test_rejects_a_rating_below_the_minimum(): void
    {
        $workOrder = $this->makeCompletedWorkOrder();

        $this->expectException(InvalidArgumentException::class);

        app(AcceptService::class)($workOrder, (string) Str::uuid(), 0, null);
    }

    public function test_rejects_a_rating_above_the_maximum(): void
    {
        $workOrder = $this->makeCompletedWorkOrder();

        $this->expectException(InvalidArgumentException::class);

        app(AcceptService::class)($workOrder, (string) Str::uuid(), 6, null);
    }

    public function test_an_invalid_rating_writes_no_acceptance_or_audit_row(): void
    {
        $workOrder = $this->makeCompletedWorkOrder();

        try {
            app(AcceptService::class)($workOrder, (string) Str::uuid(), 7, null);
            $this->fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException) {
            // expected
        }

        $this->assertSame(0, ServiceAcceptance::query()->count());
        $this->assertDatabaseMissing('audit_events', [
            'action' => VendorFulfillmentAuditActions::SERVICE_ACCEPTED,
            'subject_id' => (string) $workOrder->getKey(),
        ]);
    }

    public function test_allows_multiple_acceptance_records_for_the_same_work_order(): void
    {
        // AcceptService imposes no uniqueness of its own — the model carries
        // no unique index on work_order_id, so a second acceptance call (a
        // resubmitted form, a retry) is not rejected here. Documented via
        // this test rather than assumed.
        $workOrder = $this->makeCompletedWorkOrder();
        $customerId = (string) Str::uuid();

        app(AcceptService::class)($workOrder, $customerId, 4, 'first');
        app(AcceptService::class)($workOrder, $customerId, 5, 'second');

        $this->assertSame(2, ServiceAcceptance::query()
            ->where('work_order_id', $workOrder->getKey())
            ->count());
    }
}
