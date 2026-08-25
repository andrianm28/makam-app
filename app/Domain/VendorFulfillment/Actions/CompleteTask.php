<?php

declare(strict_types=1);

namespace App\Domain\VendorFulfillment\Actions;

use App\Domain\VendorFulfillment\Models\WorkOrderTask;
use App\Domain\VendorFulfillment\VendorFulfillmentAuditActions;
use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditOutcome;
use App\Platform\Audit\AuditSource;
use App\Platform\Audit\AuditSubject;
use App\Platform\Correlation\CorrelationContext;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Toggles a work order task status (pending → completed).
 * Sets completed_at timestamp. Audited `TASK_COMPLETED`.
 */
final readonly class CompleteTask
{
    public function __invoke(
        WorkOrderTask $task,
        string $actorRef = 'system',
        string $actorRole = 'vendor',
        AuditSource $auditSource = AuditSource::Panel,
    ): WorkOrderTask {
        return Audit::wrap(
            mutation: function () use ($task): WorkOrderTask {
                $current = WorkOrderTask::query()->lockForUpdate()->findOrFail($task->getKey());

                if ($current->status === 'completed') {
                    throw new InvalidArgumentException(
                        "Task [{$current->getKey()}] is already completed."
                    );
                }

                $current->status = 'completed';
                $current->completed_at = CarbonImmutable::now();
                $current->save();

                if ($task !== $current) {
                    $task->setRawAttributes($current->getAttributes(), true);
                }

                return $task;
            },
            action: VendorFulfillmentAuditActions::TASK_COMPLETED,
            subject: fn (WorkOrderTask $t): AuditSubject => new AuditSubject(
                'work_order_task',
                $t->getKey(),
            ),
            outcome: AuditOutcome::Allowed,
            actorRef: $actorRef,
            actorRole: $actorRole,
            source: $auditSource,
            correlationId: app(CorrelationContext::class)->current()?->value,
        );
    }
}
