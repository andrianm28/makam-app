<?php

declare(strict_types=1);

namespace App\Platform\DocumentVault\Actions;

use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditOutcome;
use App\Platform\Audit\AuditSource;
use App\Platform\Audit\AuditSubject;
use App\Platform\DocumentVault\DocumentState;
use App\Platform\DocumentVault\Models\Document;
use App\Platform\Outbox\Outbox;
use App\Platform\Outbox\OutboxClassification;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Applies logical deletion without deleting the private object or any scan
 * evidence. A legal/evidence hold is a hard stop and the sensitive audit
 * action requires the caller's reason.
 */
final class RetainDocument
{
    public function retain(Document $document, string $reason): Document
    {
        if ($this->hasHold($document)) {
            throw new LogicException('Document retention is blocked by a legal or evidence hold.');
        }

        return DB::transaction(function () use ($document, $reason): Document {
            $lockedDocument = Document::query()->lockForUpdate()->find($document->getKey());

            if (! $lockedDocument instanceof Document) {
                throw (new ModelNotFoundException)->setModel(Document::class, [$document->getKey()]);
            }

            if ($this->hasHold($lockedDocument)) {
                throw new LogicException('Document retention is blocked by a legal or evidence hold.');
            }

            if ($lockedDocument->state === DocumentState::Deleted) {
                throw new LogicException('A deleted document cannot be retained again.');
            }

            $retentionDays = config("document-vault.retention_days.{$lockedDocument->document_kind->value}");

            if (! is_int($retentionDays) || $retentionDays < 1) {
                throw new LogicException('Document retention policy is not configured.');
            }

            $lockedDocument->fill(['retention_until' => now()->addDays($retentionDays)]);
            $lockedDocument->transitionTo(DocumentState::Deleted);

            Audit::record(
                action: 'DOCUMENT_DELETE',
                subject: new AuditSubject('document', $lockedDocument->getKey()),
                outcome: AuditOutcome::Allowed,
                actorRef: null,
                actorRole: 'system',
                source: AuditSource::Job,
                reason: $reason,
            );

            Outbox::record(
                eventName: 'document.deleted.v1',
                eventVersion: 1,
                aggregateType: 'document',
                aggregateId: $lockedDocument->getKey(),
                data: [
                    'kind' => $lockedDocument->document_kind->value,
                    'state' => 'deleted',
                ],
                classification: OutboxClassification::Confidential,
                idempotencyKey: "delete:{$lockedDocument->getKey()}",
            );

            return $lockedDocument;
        });
    }

    private function hasHold(Document $document): bool
    {
        return (bool) $document->legal_hold || (bool) $document->evidence_hold;
    }
}
