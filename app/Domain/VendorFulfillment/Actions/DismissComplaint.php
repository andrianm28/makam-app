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
use Carbon\CarbonImmutable;

/**
 * Dismisses a complaint (OPEN or INVESTIGATING -> DISMISSED) with a
 * required reason, stored in the same `resolution_notes` column a real
 * resolution uses — a dismissal reason and a resolution note are the same
 * kind of "why this complaint is closed" text (spec §1).
 */
final readonly class DismissComplaint
{
    public function __invoke(
        ServiceComplaint $complaint,
        string $reason,
        ?string $actorRole = null,
        ?AuditSource $source = null,
        ?string $actorRef = null,
    ): ServiceComplaint {
        if (! in_array($complaint->status, [ComplaintStatus::Open->value, ComplaintStatus::Investigating->value], true)) {
            throw new InvalidComplaintTransitionException(
                "Cannot dismiss complaint [{$complaint->getKey()}] from status [{$complaint->status}]; only OPEN or INVESTIGATING complaints can be dismissed."
            );
        }

        return Audit::wrap(
            mutation: function () use ($complaint, $reason): ServiceComplaint {
                $complaint->forceFill([
                    'status' => ComplaintStatus::Dismissed->value,
                    'resolution_notes' => $reason,
                    'resolved_at' => CarbonImmutable::now(),
                ])->save();

                Outbox::record(
                    eventName: 'care.complaint_dismissed.v1',
                    eventVersion: 1,
                    aggregateType: 'service_complaint',
                    aggregateId: $complaint->getKey(),
                    data: ['complaint_id' => $complaint->getKey()],
                    classification: OutboxClassification::Internal,
                    idempotencyKey: "complaint_dismissed:{$complaint->getKey()}",
                );

                return $complaint->fresh();
            },
            action: VendorFulfillmentAuditActions::COMPLAINT_DISMISSED,
            subject: new AuditSubject('service_complaint', $complaint->getKey()),
            outcome: AuditOutcome::Allowed,
            actorRef: $actorRef,
            actorRole: $actorRole ?? 'system',
            source: $source ?? AuditSource::Job,
            correlationId: app(CorrelationContext::class)->current()?->value,
        );
    }
}
