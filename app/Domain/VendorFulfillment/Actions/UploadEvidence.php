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
use App\Platform\DocumentVault\DocumentState;
use App\Platform\DocumentVault\Models\Document;
use InvalidArgumentException;

/**
 * Records evidence upload against a work order.
 * References a `documents` row in the vault (private quarantine).
 * Audited `EVIDENCE_UPLOADED`.
 */
final readonly class UploadEvidence
{
    /**
     * @var list<string>
     */
    private const array VALID_EVIDENCE_TYPES = ['before', 'after'];

    public function __invoke(
        WorkOrder $workOrder,
        string $documentId,
        string $evidenceType,
        ?string $uploadedBy = null,
        string $actorRef = 'system',
        string $actorRole = 'vendor',
        AuditSource $auditSource = AuditSource::Panel,
    ): WorkEvidence {
        if (! in_array($evidenceType, self::VALID_EVIDENCE_TYPES, true)) {
            throw new InvalidArgumentException("evidence_type must be 'before' or 'after'");
        }

        $document = Document::query()->findOrFail($documentId);

        if ($document->state !== DocumentState::Accepted) {
            throw new InvalidArgumentException('evidence requires an ACCEPTED document');
        }

        return Audit::wrap(
            mutation: function () use ($workOrder, $documentId, $evidenceType, $uploadedBy): WorkEvidence {
                return WorkEvidence::query()->create([
                    'work_order_id' => $workOrder->getKey(),
                    'document_id' => $documentId,
                    'evidence_type' => $evidenceType,
                    'uploaded_by' => $uploadedBy,
                ]);
            },
            action: VendorFulfillmentAuditActions::EVIDENCE_UPLOADED,
            subject: new AuditSubject('work_order', $workOrder->getKey()),
            outcome: AuditOutcome::Allowed,
            actorRef: $actorRef,
            actorRole: $actorRole,
            source: $auditSource,
            correlationId: app(CorrelationContext::class)->current()?->value,
        );
    }
}
