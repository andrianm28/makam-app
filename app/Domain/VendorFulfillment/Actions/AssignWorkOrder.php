<?php

declare(strict_types=1);

namespace App\Domain\VendorFulfillment\Actions;

use App\Domain\VendorFulfillment\Models\WorkOrder;
use App\Domain\VendorFulfillment\VendorFulfillmentAuditActions;
use App\Domain\VendorFulfillment\WorkOrderStatus;
use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditOutcome;
use App\Platform\Audit\AuditSource;
use App\Platform\Audit\AuditSubject;
use App\Platform\Correlation\CorrelationContext;
use App\Platform\Outbox\Outbox;
use App\Platform\Outbox\OutboxClassification;
use InvalidArgumentException;

/**
 * Assigns a vendor to a pending work order, transitioning it to ASSIGNED.
 *
 * QUE-10 (Batch M1a, 07 Sep 2026): this transition used to write only an
 * audit row, though `vendor.order_assigned` is on `queue-and-outbox.md`
 * §6's mandatory events-requiring-outbox list. `vendor.order_assigned.v1`
 * is now recorded inside the same `Audit::wrap()` mutation closure,
 * references only — work order, vendor, and care plan/cycle ids, mirroring
 * `Actions\CreateWorkOrder`'s own `care.work_order_created.v1` payload
 * shape for this domain.
 */
final readonly class AssignWorkOrder
{
    public function __invoke(
        WorkOrder $workOrder,
        string $vendorId,
        string $actorReference,
    ): WorkOrder {
        if ($workOrder->status !== WorkOrderStatus::Pending->value) {
            throw new InvalidArgumentException(
                "work_orders row [{$workOrder->getKey()}] is [{$workOrder->status}]; only a PENDING work order can be assigned."
            );
        }

        return Audit::wrap(
            mutation: function () use ($workOrder, $vendorId): WorkOrder {
                $workOrder->update([
                    'vendor_id' => $vendorId,
                    'assigned_to' => $vendorId,
                    'status' => WorkOrderStatus::Assigned->value,
                ]);

                Outbox::record(
                    eventName: 'vendor.order_assigned.v1',
                    eventVersion: 1,
                    aggregateType: 'work_order',
                    aggregateId: $workOrder->getKey(),
                    data: [
                        'work_order_id' => $workOrder->getKey(),
                        'vendor_id' => $vendorId,
                        'care_plan_id' => $workOrder->care_plan_id,
                        'subscription_cycle_id' => $workOrder->subscription_cycle_id,
                    ],
                    classification: OutboxClassification::Internal,
                    idempotencyKey: "vendor_order_assigned:{$workOrder->getKey()}",
                );

                return $workOrder->fresh();
            },
            action: VendorFulfillmentAuditActions::WORK_ORDER_ASSIGNED,
            subject: new AuditSubject('work_order', $workOrder->getKey()),
            outcome: AuditOutcome::Allowed,
            actorRef: $actorReference,
            actorRole: 'operator',
            source: AuditSource::Panel,
            correlationId: app(CorrelationContext::class)->current()?->value,
        );
    }
}
