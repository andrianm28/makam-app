<?php

declare(strict_types=1);

namespace App\Domain\VendorFulfillment\Actions;

use App\Domain\VendorFulfillment\Models\WorkOrder;
use App\Domain\VendorFulfillment\VendorFulfillmentAuditActions;
use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditOutcome;
use App\Platform\Audit\AuditSource;
use App\Platform\Audit\AuditSubject;
use App\Platform\Correlation\CorrelationContext;

/**
 * Replaces the vendor assigned to a work order (AC7). Records both the
 * old and new vendor IDs in the audit metadata for traceability.
 */
final readonly class ReplaceVendor
{
    public function __invoke(
        WorkOrder $workOrder,
        string $newVendorId,
        string $reason,
        string $actorReference,
    ): WorkOrder {
        $oldVendorId = $workOrder->vendor_id;

        return Audit::wrap(
            mutation: function () use ($workOrder, $newVendorId): WorkOrder {
                $workOrder->update([
                    'vendor_id' => $newVendorId,
                ]);

                return $workOrder->fresh();
            },
            action: VendorFulfillmentAuditActions::VENDOR_REPLACED,
            subject: new AuditSubject('work_order', $workOrder->getKey()),
            outcome: AuditOutcome::Allowed,
            actorRef: $actorReference,
            actorRole: 'operator',
            source: AuditSource::Panel,
            reason: $reason,
            metadata: [
                'previous_state' => $oldVendorId,
                'new_state' => $newVendorId,
            ],
            correlationId: app(CorrelationContext::class)->current()?->value,
        );
    }
}
