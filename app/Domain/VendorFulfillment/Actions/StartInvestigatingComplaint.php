<?php

declare(strict_types=1);

namespace App\Domain\VendorFulfillment\Actions;

use App\Domain\VendorFulfillment\ComplaintStatus;
use App\Domain\VendorFulfillment\Exceptions\InvalidComplaintTransitionException;
use App\Domain\VendorFulfillment\Models\ServiceComplaint;
use App\Domain\VendorFulfillment\VendorFulfillmentAuditActions;
use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditOutcome;
use App\Platform\Audit\AuditSource;
use App\Platform\Audit\AuditSubject;
use App\Platform\Correlation\CorrelationContext;
use App\Platform\Outbox\Outbox;
use App\Platform\Outbox\OutboxClassification;

/**
 * Moves a complaint from OPEN to INVESTIGATING — staff acknowledging they
 * are looking into it. Refuses any other source status.
 */
final readonly class StartInvestigatingComplaint
{
    public function __invoke(
        ServiceComplaint $complaint,
        ?string $actorRole = null,
        ?AuditSource $source = null,
        ?string $actorRef = null,
    ): ServiceComplaint {
        if ($complaint->status !== ComplaintStatus::Open->value) {
            throw new InvalidComplaintTransitionException(
                "Cannot start investigating complaint [{$complaint->getKey()}] from status [{$complaint->status}]; only OPEN complaints can move to INVESTIGATING."
            );
        }

        return Audit::wrap(
            mutation: function () use ($complaint): ServiceComplaint {
                $complaint->forceFill(['status' => ComplaintStatus::Investigating->value])->save();

                Outbox::record(
                    eventName: 'care.complaint_investigating.v1',
                    eventVersion: 1,
                    aggregateType: 'service_complaint',
                    aggregateId: $complaint->getKey(),
                    data: ['complaint_id' => $complaint->getKey()],
                    classification: OutboxClassification::Internal,
                    idempotencyKey: "complaint_investigating:{$complaint->getKey()}",
                );

                return $complaint->fresh();
            },
            action: VendorFulfillmentAuditActions::COMPLAINT_INVESTIGATING,
            subject: new AuditSubject('service_complaint', $complaint->getKey()),
            outcome: AuditOutcome::Allowed,
            actorRef: $actorRef,
            actorRole: $actorRole ?? 'system',
            source: $source ?? AuditSource::Job,
            correlationId: app(CorrelationContext::class)->current()?->value,
        );
    }
}
