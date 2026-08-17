<?php

declare(strict_types=1);

namespace App\Domain\VendorFulfillment\Actions;

use App\Domain\CareSubscription\Models\CarePlan;
use App\Domain\CareSubscription\Models\SubscriptionCycle;
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
 * Creates a work order from a paid subscription cycle. Idempotent: if a
 * work order already exists for this cycle, it is returned unchanged.
 *
 * Expands `care_plan.checklist_template` into `work_order_tasks` rows.
 */
final readonly class CreateWorkOrderFromCycle
{
    /**
     * @param  SubscriptionCycle  $cycle  The paid subscription cycle
     * @param  CarePlan  $carePlan  The care plan providing the checklist template
     */
    public function __invoke(
        SubscriptionCycle $cycle,
        CarePlan $carePlan,
    ): WorkOrder {
        return Audit::wrap(
            mutation: function () use ($cycle, $carePlan): WorkOrder {
                $existing = WorkOrder::query()
                    ->where('subscription_cycle_id', $cycle->getKey())
                    ->first();

                if ($existing instanceof WorkOrder) {
                    return $existing;
                }

                $workOrder = WorkOrder::query()->create([
                    'reference' => 'WO-'.Str::upper(Str::random(8)),
                    'care_plan_id' => $carePlan->getKey(),
                    'subscription_cycle_id' => $cycle->getKey(),
                    'status' => WorkOrderStatus::Pending->value,
                ]);

                $template = $carePlan->checklist_template ?? [];

                foreach ($template as $index => $item) {
                    $name = is_array($item) ? ($item['name'] ?? '') : (string) $item;
                    $requiredEvidence = is_array($item) ? ($item['required_evidence'] ?? false) : false;

                    WorkOrderTask::query()->create([
                        'work_order_id' => $workOrder->getKey(),
                        'name' => $name,
                        'required_evidence' => $requiredEvidence,
                        'sort_order' => $index + 1,
                    ]);
                }

                Outbox::record(
                    eventName: 'care.work_order_created.v1',
                    eventVersion: 1,
                    aggregateType: 'work_order',
                    aggregateId: $workOrder->getKey(),
                    data: [
                        'work_order_id' => $workOrder->getKey(),
                        'care_plan_id' => $carePlan->getKey(),
                        'subscription_cycle_id' => $cycle->getKey(),
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
            actorRef: null,
            actorRole: 'system',
            source: AuditSource::Job,
            correlationId: app(CorrelationContext::class)->current()?->value,
        );
    }
}
