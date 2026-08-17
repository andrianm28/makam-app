<?php

declare(strict_types=1);

namespace App\Domain\VendorFulfillment\Actions;

use App\Domain\VendorFulfillment\ComplaintStatus;
use App\Domain\VendorFulfillment\Models\ServiceComplaint;
use App\Domain\VendorFulfillment\Models\WorkOrder;
use App\Domain\VendorFulfillment\VendorFulfillmentAuditActions;
use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditOutcome;
use App\Platform\Audit\AuditSource;
use App\Platform\Audit\AuditSubject;
use App\Platform\Correlation\CorrelationContext;
use App\Platform\Outbox\Outbox;
use App\Platform\Outbox\OutboxClassification;
use Carbon\CarbonImmutable;

/**
 * Files a customer complaint about a work order. Creates the complaint in
 * OPEN status and emits an outbox event.
 */
final readonly class FileComplaint
{
    public function __invoke(
        WorkOrder $workOrder,
        string $customerId,
        string $complaintText,
    ): ServiceComplaint {
        return Audit::wrap(
            mutation: function () use ($workOrder, $customerId, $complaintText): ServiceComplaint {
                $complaint = ServiceComplaint::query()->create([
                    'work_order_id' => $workOrder->getKey(),
                    'customer_id' => $customerId,
                    'complaint_text' => $complaintText,
                    'status' => ComplaintStatus::Open->value,
                    'filed_at' => CarbonImmutable::now(),
                ]);

                Outbox::record(
                    eventName: 'care.complaint_filed.v1',
                    eventVersion: 1,
                    aggregateType: 'service_complaint',
                    aggregateId: $complaint->getKey(),
                    data: [
                        'complaint_id' => $complaint->getKey(),
                        'work_order_id' => $workOrder->getKey(),
                        'customer_id' => $customerId,
                    ],
                    classification: OutboxClassification::Internal,
                    idempotencyKey: "complaint_filed:{$complaint->getKey()}",
                );

                return $complaint;
            },
            action: VendorFulfillmentAuditActions::COMPLAINT_FILED,
            subject: new AuditSubject('work_order', $workOrder->getKey()),
            outcome: AuditOutcome::Allowed,
            actorRef: $customerId,
            actorRole: 'customer',
            source: AuditSource::Api,
            correlationId: app(CorrelationContext::class)->current()?->value,
        );
    }
}
