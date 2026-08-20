<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\VendorFulfillment;

use App\Domain\CareSubscription\Models\CarePlan;
use App\Domain\Marketplace\Models\Vendor;
use App\Domain\VendorFulfillment\Actions\ReplaceVendor;
use App\Domain\VendorFulfillment\Models\WorkOrder;
use App\Domain\VendorFulfillment\WorkOrderStatus;
use App\Platform\Audit\Models\AuditEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * `App\Domain\VendorFulfillment\Actions\ReplaceVendor` (AC7) had zero test
 * coverage anywhere in the repo before this file — it existed, audited,
 * since the domain lane shipped, but nothing called it and nothing proved
 * its contract. Proves: the work order's `vendor_id` moves to the new
 * vendor, and the audit row records both the previous and new vendor in
 * `metadata` (`previous_state`/`new_state`) plus the given `reason`.
 */
final class ReplaceVendorTest extends TestCase
{
    use RefreshDatabase;

    private function makeWorkOrder(?Vendor $vendor = null): WorkOrder
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

        return WorkOrder::query()->create([
            'reference' => 'WO-'.Str::upper(Str::random(8)),
            'care_plan_id' => $carePlan->getKey(),
            'vendor_id' => $vendor?->getKey(),
            'status' => WorkOrderStatus::Scheduled->value,
        ]);
    }

    public function test_it_moves_the_work_order_to_the_new_vendor_and_records_both_ids_in_the_audit(): void
    {
        $oldVendor = Vendor::query()->create(['name' => 'Vendor Lama', 'is_active' => true]);
        $newVendor = Vendor::query()->create(['name' => 'Vendor Baru', 'is_active' => true]);
        $workOrder = $this->makeWorkOrder($oldVendor);

        $result = app(ReplaceVendor::class)(
            $workOrder,
            (string) $newVendor->getKey(),
            'Vendor lama tidak responsif.',
            'admin:1',
        );

        $this->assertSame((string) $newVendor->getKey(), $result->vendor_id);
        $this->assertSame((string) $newVendor->getKey(), $workOrder->fresh()->vendor_id);

        $this->assertDatabaseHas('audit_events', [
            'action' => 'VENDOR_REPLACED',
            'subject_type' => 'work_order',
            'subject_id' => (string) $workOrder->getKey(),
            'actor_ref' => 'admin:1',
            'actor_role' => 'operator',
            'reason' => 'Vendor lama tidak responsif.',
        ]);

        $event = AuditEvent::query()
            ->where('action', 'VENDOR_REPLACED')
            ->where('subject_id', (string) $workOrder->getKey())
            ->sole();

        $this->assertSame(
            [
                'previous_state' => (string) $oldVendor->getKey(),
                'new_state' => (string) $newVendor->getKey(),
            ],
            $event->metadata,
        );
    }

    public function test_it_assigns_a_vendor_to_a_work_order_that_previously_had_none(): void
    {
        $newVendor = Vendor::query()->create(['name' => 'Vendor Baru', 'is_active' => true]);
        $workOrder = $this->makeWorkOrder(null);

        app(ReplaceVendor::class)(
            $workOrder,
            (string) $newVendor->getKey(),
            'Penugasan awal.',
            'admin:1',
        );

        $this->assertSame((string) $newVendor->getKey(), $workOrder->fresh()->vendor_id);

        $event = AuditEvent::query()
            ->where('action', 'VENDOR_REPLACED')
            ->where('subject_id', (string) $workOrder->getKey())
            ->sole();

        $this->assertNull($event->metadata['previous_state']);
    }
}
