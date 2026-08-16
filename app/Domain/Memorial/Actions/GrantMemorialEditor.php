<?php

declare(strict_types=1);

namespace App\Domain\Memorial\Actions;

use App\Domain\Memorial\Exceptions\MemorialConsentMissingException;
use App\Domain\Memorial\MemorialAuditActions;
use App\Domain\Memorial\Models\MemorialEditor;
use App\Domain\Memorial\Models\MemorialProfile;
use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditOutcome;
use App\Platform\Audit\AuditSource;
use App\Platform\Audit\AuditSubject;
use App\Platform\Correlation\CorrelationContext;

/**
 * AC1's consent-gated editor grant: *"THE SYSTEM SHALL require
 * authority/consent evidence before granting editor access."*
 *
 * A grant without a non-blank `$consentEvidenceRef` (the vault
 * `documents.id` of the recorded consent evidence) throws
 * `MemorialConsentMissingException` BEFORE any row is written — the
 * audit trail must always be able to point at the evidence that
 * authorised the grant.
 *
 * Idempotent: granting an actor who already holds an ACTIVE editor row
 * returns the incumbent and writes nothing (the partial unique index on
 * `(memorial_profile_id, actor_id) WHERE revoked_at IS NULL` is the
 * database backstop). A REVOKED editor can be granted again — a new row
 * with a new `granted_at`.
 */
final readonly class GrantMemorialEditor
{
    public function __invoke(
        MemorialProfile $profile,
        int|string $actorId,
        string $consentEvidenceRef,
        int|string $actorReference,
        string $actorRole,
        ?AuditSource $auditSource = null,
    ): MemorialEditor {
        if (Audit::reasonIsBlank($consentEvidenceRef)) {
            throw MemorialConsentMissingException::forGrant($profile->getKey(), $actorId);
        }

        $incumbent = $profile->editors()
            ->where('actor_id', (string) $actorId)
            ->whereNull('revoked_at')
            ->first();

        if ($incumbent instanceof MemorialEditor) {
            return $incumbent;
        }

        return Audit::wrap(
            mutation: fn (): MemorialEditor => $profile->editors()->create([
                'actor_id' => (string) $actorId,
                'consent_evidence_ref' => $consentEvidenceRef,
                'granted_at' => now(),
            ]),
            action: MemorialAuditActions::MEMORIAL_EDITOR_GRANTED,
            subject: fn (MemorialEditor $editor): AuditSubject => new AuditSubject('memorial_editor', $editor->getKey()),
            outcome: AuditOutcome::Allowed,
            actorRef: $actorReference,
            actorRole: $actorRole,
            source: $auditSource ?? AuditSource::Panel,
            correlationId: app(CorrelationContext::class)->current()?->value,
        );
    }
}
