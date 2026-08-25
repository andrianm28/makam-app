<?php

declare(strict_types=1);

namespace App\Domain\VendorFulfillment\Actions;

use App\Domain\CareSubscription\Models\CarePlan;
use App\Domain\VendorFulfillment\Models\WorkOrder;
use App\Domain\VendorFulfillment\Models\WorkOrderTask;
use App\Domain\VendorFulfillment\VendorFulfillmentAuditActions;
use App\Domain\VendorFulfillment\WorkOrderStatus;
use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditOutcome;
use App\Platform\Audit\AuditSource;
use App\Platform\Audit\AuditSubject;
use App\Platform\Correlation\CorrelationContext;
use App\Platform\Outbox\Outbox;
use App\Platform\Outbox\OutboxClassification;
use Illuminate\Support\Str;

/**
 * Creates a work order directly (not from a cycle) — one-off or manual creation.
 * For cycle-derived work orders, use `CreateWorkOrderFromCycle`.
 */
final readonly class CreateWorkOrder
{
    public function __invoke(
        CarePlan $carePlan,
        ?string $cycleId = null,
        ?string $vendorId = null,
        ?string $scheduledAt = null,
        ?array $checklistItems = null,
        string $actorRef = 'system',
        string $actorRole = 'admin',
        AuditSource $auditSource = AuditSource::Panel,
    ): WorkOrder {
        return Audit::wrap(
            mutation: function () use ($carePlan, $cycleId, $vendorId, $scheduledAt, $checklistItems): WorkOrder {
                $workOrder = WorkOrder::query()->create([
                    'reference' => 'WO-'.Str::upper(Str::random(8)),
                    'care_plan_id' => $carePlan->getKey(),
                    'subscription_cycle_id' => $cycleId,
                    'vendor_id' => $vendorId,
                    'status' => WorkOrderStatus::Scheduled->value,
                    'scheduled_at' => $scheduledAt,
                ]);

                if ($checklistItems !== null) {
                    foreach ($checklistItems as $index => $item) {
                        WorkOrderTask::query()->create([
                            'work_order_id' => $workOrder->getKey(),
                            'name' => is_array($item) ? ($item['name'] ?? '') : (string) $item,
                            'required_evidence' => is_array($item) ? ($item['required_evidence'] ?? false) : false,
                            'sort_order' => $index + 1,
                        ]);
                    }
                }

                Outbox::record(
                    eventName: 'care.work_order_created.v1',
                    eventVersion: 1,
                    aggregateType: 'work_order',
                    aggregateId: $workOrder->getKey(),
                    data: [
                        'work_order_id' => $workOrder->getKey(),
                        'care_plan_id' => $carePlan->getKey(),
                        'subscription_cycle_id' => $cycleId,
                    ],
                    classification: OutboxClassification::Internal,
                    idempotencyKey: "work_order_created:{$workOrder->getKey()}",
                );

                return $workOrder;
            },
            action: VendorFulfillmentAuditActions::WORK_ORDER_CREATED,
            subject: fn (WorkOrder $wo): AuditSubject => new AuditSubject(
                'work_order',
                $wo->getKey(),
            ),
            outcome: AuditOutcome::Allowed,
            actorRef: $actorRef,
            actorRole: $actorRole,
            source: $auditSource,
            correlationId: app(CorrelationContext::class)->current()?->value,
        );
    }
}
