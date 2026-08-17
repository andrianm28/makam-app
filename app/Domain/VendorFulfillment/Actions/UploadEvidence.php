<?php

declare(strict_types=1);

namespace App\Domain\VendorFulfillment\Actions;

use App\Domain\VendorFulfillment\Models\WorkEvidence;
use App\Domain\VendorFulfillment\Models\WorkOrder;
use App\Domain\VendorFulfillment\VendorFulfillmentAuditActions;
use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditOutcome;
use App\Platform\Audit\AuditSource;
use App\Platform\Audit\AuditSubject;
use App\Platform\Correlation\CorrelationContext;

/**
 * Records evidence upload against a work order.
 * Evidence enters quarantine (status: pending) until validated.
 * Audited `EVIDENCE_UPLOADED`.
 */
final readonly class UploadEvidence
{
    public function __invoke(
        WorkOrder $workOrder,
        string $evidenceType,
        string $filePath,
        string $fileName,
        ?string $fileType = null,
        ?int $fileSize = null,
        ?string $uploadedBy = null,
        string $actorRef = 'system',
        string $actorRole = 'vendor',
        AuditSource $auditSource = AuditSource::Panel,
    ): WorkEvidence {
        return Audit::wrap(
            mutation: function () use ($workOrder, $evidenceType, $filePath, $fileName, $fileType, $fileSize, $uploadedBy): WorkEvidence {
                return WorkEvidence::query()->create([
                    'work_order_id' => $workOrder->getKey(),
                    'evidence_type' => $evidenceType,
                    'file_path' => $filePath,
                    'file_name' => $fileName,
                    'file_type' => $fileType,
                    'file_size' => $fileSize,
                    'status' => 'pending',
                    'uploaded_by' => $uploadedBy,
                ]);
            },
            action: VendorFulfillmentAuditActions::EVIDENCE_UPLOADED,
            subject: fn (WorkEvidence $ev): AuditSubject => new AuditSubject(
                'work_evidence',
                $ev->getKey(),
            ),
            outcome: AuditOutcome::Allowed,
            actorRef: $actorRef,
            actorRole: $actorRole,
            source: $auditSource,
            correlationId: app(CorrelationContext::class)->current()?->value,
        );
    }
}
