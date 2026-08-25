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
use InvalidArgumentException;

/**
 * Assigns a vendor to a pending work order, transitioning it to ASSIGNED.
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
