<?php

declare(strict_types=1);

namespace App\Platform\DocumentVault\Actions;

use App\Platform\Audit\AuditOutcome;
use App\Platform\DocumentVault\DocumentAccessPurpose;
use App\Platform\DocumentVault\Models\Document;
use App\Platform\DocumentVault\Models\DocumentAccessEvent;
use App\Platform\DocumentVault\Policies\DocumentAccessPolicy;
use App\Platform\IdentityAccess\ActorContext;
use App\Platform\Outbox\Outbox;
use App\Platform\Outbox\OutboxClassification;
use Illuminate\Support\Facades\DB;

/**
 * Appends one `document_access_events` row for an ACTUAL access to a
 * restricted document — the module's single write API for that table
 * alongside `IssueSignedUrl`'s issuance rows, and AC8's evidence:
 * "audit actor/purpose/record/timestamp/outcome on every restricted access."
 *
 * Task 7's private download route is the intended caller, on every request it
 * serves AND on every request it refuses, so a probing attempt is evidence
 * too, not silence.
 *
 * ---------------------------------------------------------------------------
 * `document.accessed.v1` — allowed accesses only
 * ---------------------------------------------------------------------------
 * The event is already in `docs/contracts/event-catalog.md:24` with
 * classification RESTRICTED, and its payload is fixed by this lane's Global
 * Constraints to the document reference plus kind and state — no filename, no
 * MIME type, no storage path, no token, no content, and (because the payload
 * shape carries no outcome field) no way for a subscriber to tell an allowed
 * access from a refused one. Publishing a refusal under an event named
 * "accessed" with a payload that cannot say otherwise would actively mislead
 * every subscriber, so only an ALLOWED access publishes. The refusal is still
 * fully recorded in `document_access_events` (append-only) and, on the
 * issuance path, in `audit_events` — neither of which loses the denial.
 *
 * Idempotency is per ACCESS EVENT, not per document: the same document is
 * legitimately accessed many times, and each access is its own event. The key
 * is therefore derived from the freshly-inserted access event's primary key,
 * which is why the row is written before the outbox record, inside one
 * transaction — a retried job that re-runs this method produces a new access
 * event and a new key, while a duplicate publish of the SAME access event is
 * blocked by `outbox_events.idempotency_key`'s UNIQUE constraint.
 */
final class RecordDocumentAccess
{
    public function record(
        Document $document,
        ActorContext $actor,
        DocumentAccessPurpose $purpose,
        AuditOutcome $outcome,
        ?string $ipAddress = null,
    ): DocumentAccessEvent {
        return DB::transaction(function () use (
            $document,
            $actor,
            $purpose,
            $outcome,
            $ipAddress,
        ): DocumentAccessEvent {
            $event = DocumentAccessEvent::recordAccess(
                document: $document,
                actorRef: $actor->identityReference,
                actorRole: DocumentAccessPolicy::auditRoleFor($actor),
                purpose: $purpose,
                outcome: $outcome,
                ipAddress: $ipAddress,
            );

            if ($outcome === AuditOutcome::Allowed) {
                Outbox::record(
                    eventName: 'document.accessed.v1',
                    eventVersion: 1,
                    aggregateType: 'document',
                    aggregateId: $document->getKey(),
                    data: [
                        'kind' => $document->document_kind->value,
                        // Derived, not the literal 'accepted': the state is
                        // real information about the record, and hardcoding
                        // it would silently lie if a future caller ever
                        // records an allowed access in another state.
                        'state' => mb_strtolower($document->state->value),
                    ],
                    classification: OutboxClassification::Restricted,
                    idempotencyKey: "access:{$event->getKey()}",
                );
            }

            return $event;
        });
    }
}
