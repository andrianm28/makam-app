<?php

declare(strict_types=1);

namespace App\Domain\VendorFulfillment\Actions;

use App\Domain\VendorFulfillment\ComplaintStatus;
use App\Domain\VendorFulfillment\Exceptions\InvalidComplaintTransitionException;
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
 * Resolves a complaint (OPEN or INVESTIGATING -> RESOLVED), optionally
 * creating and linking a make-good order in the same transaction. See
 * spec §1 — the `make_good_order_id` linkage is a real, previously
 * missing relationship, not a workaround.
 */
final readonly class ResolveComplaint
{
    public function __invoke(
        ServiceComplaint $complaint,
        string $resolutionNotes,
        bool $createMakeGood,
        ?string $makeGoodNotes = null,
        ?string $actorRole = null,
        ?AuditSource $source = null,
        ?string $actorRef = null,
    ): ServiceComplaint {
        if (! in_array($complaint->status, [ComplaintStatus::Open->value, ComplaintStatus::Investigating->value], true)) {
            throw new InvalidComplaintTransitionException(
                "Cannot resolve complaint [{$complaint->getKey()}] from status [{$complaint->status}]; only OPEN or INVESTIGATING complaints can be resolved."
            );
        }

        return Audit::wrap(
            mutation: function () use ($complaint, $resolutionNotes, $createMakeGood, $makeGoodNotes, $actorRole, $source, $actorRef): ServiceComplaint {
                $makeGoodOrderId = null;

                if ($createMakeGood) {
                    /** @var WorkOrder $workOrder */
                    $workOrder = $complaint->workOrder()->firstOrFail();

                    $makeGood = app(CreateMakeGood::class)(
                        $workOrder,
                        $makeGoodNotes,
                        $actorRole,
                        $source,
                        $actorRef,
                    );

                    $makeGoodOrderId = $makeGood->getKey();
                }

                $complaint->forceFill([
                    'status' => ComplaintStatus::Resolved->value,
                    'resolution_notes' => $resolutionNotes,
                    'resolved_at' => CarbonImmutable::now(),
                    'make_good_order_id' => $makeGoodOrderId,
                ])->save();

                Outbox::record(
                    eventName: 'care.complaint_resolved.v1',
                    eventVersion: 1,
                    aggregateType: 'service_complaint',
                    aggregateId: $complaint->getKey(),
                    data: [
                        'complaint_id' => $complaint->getKey(),
                        'make_good_order_id' => $makeGoodOrderId,
                    ],
                    classification: OutboxClassification::Internal,
                    idempotencyKey: "complaint_resolved:{$complaint->getKey()}",
                );

                return $complaint->fresh();
            },
            action: VendorFulfillmentAuditActions::COMPLAINT_RESOLVED,
            subject: new AuditSubject('service_complaint', $complaint->getKey()),
            outcome: AuditOutcome::Allowed,
            actorRef: $actorRef,
            actorRole: $actorRole ?? 'system',
            source: $source ?? AuditSource::Job,
            correlationId: app(CorrelationContext::class)->current()?->value,
        );
    }
}
