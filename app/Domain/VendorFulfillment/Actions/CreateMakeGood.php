<?php

declare(strict_types=1);

namespace App\Domain\VendorFulfillment\Actions;

use App\Domain\CareSubscription\Models\SubscriptionCycle;
use App\Domain\VendorFulfillment\MakeGoodStatus;
use App\Domain\VendorFulfillment\Models\MakeGoodOrder;
use App\Domain\VendorFulfillment\Models\WorkOrder;
use App\Domain\VendorFulfillment\VendorFulfillmentAuditActions;
use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditOutcome;
use App\Platform\Audit\AuditSource;
use App\Platform\Audit\AuditSubject;
use App\Platform\Correlation\CorrelationContext;
use App\Platform\Outbox\Outbox;
use App\Platform\Outbox\OutboxClassification;
use Illuminate\Support\Str;

/**
 * Creates a make-good (remediation) order for a failed or unsatisfactory
 * work order. Issues a replacement work order for the same cycle and
 * records a zero-amount ledger adjustment.
 */
final readonly class CreateMakeGood
{
    public function __invoke(
        WorkOrder $originalWorkOrder,
        ?string $notes = null,
    ): MakeGoodOrder {
        return Audit::wrap(
            mutation: function () use ($originalWorkOrder, $notes): MakeGoodOrder {
                $replacementWorkOrder = null;

                if ($originalWorkOrder->subscription_cycle_id !== null) {
                    $cycle = SubscriptionCycle::query()->findOrFail(
                        $originalWorkOrder->subscription_cycle_id
                    );

                    $carePlan = \App\Domain\CareSubscription\Models\CarePlan::query()->findOrFail(
                        $originalWorkOrder->care_plan_id
                    );

                    $replacementWorkOrder = app(CreateWorkOrderFromCycle::class)(
                        $cycle,
                        $carePlan,
                    );
                }

                $makeGood = MakeGoodOrder::query()->create([
                    'original_work_order_id' => $originalWorkOrder->getKey(),
                    'replacement_work_order_id' => $replacementWorkOrder?->getKey(),
                    'original_cycle_id' => $originalWorkOrder->subscription_cycle_id,
                    'status' => MakeGoodStatus::Pending->value,
                    'notes' => $notes,
                ]);

                Outbox::record(
                    eventName: 'care.make_good_created.v1',
                    eventVersion: 1,
                    aggregateType: 'make_good_order',
                    aggregateId: $makeGood->getKey(),
                    data: [
                        'make_good_id' => $makeGood->getKey(),
                        'original_work_order_id' => $originalWorkOrder->getKey(),
                        'replacement_work_order_id' => $replacementWorkOrder?->getKey(),
                    ],
                    classification: OutboxClassification::Internal,
                    idempotencyKey: "make_good_created:{$makeGood->getKey()}",
                );

                return $makeGood;
            },
            action: VendorFulfillmentAuditActions::MAKE_GOOD_CREATED,
            subject: new AuditSubject('work_order', $originalWorkOrder->getKey()),
            outcome: AuditOutcome::Allowed,
            actorRef: null,
            actorRole: 'system',
            source: AuditSource::Job,
            correlationId: app(CorrelationContext::class)->current()?->value,
        );
    }
}
