<?php

declare(strict_types=1);

namespace App\Domain\AgreementCertificate\Actions;

use App\Domain\AgreementCertificate\AgreementCertificateAuditActions;
use App\Domain\AgreementCertificate\CertificateIssuerAuthorizer;
use App\Domain\AgreementCertificate\CertificateStatus;
use App\Domain\AgreementCertificate\CertificateType;
use App\Domain\AgreementCertificate\Exceptions\CertificateReferenceCollisionException;
use App\Domain\AgreementCertificate\Models\Certificate;
use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditOutcome;
use App\Platform\Audit\AuditSource;
use App\Platform\Audit\AuditSubject;
use App\Platform\Correlation\CorrelationContext;
use App\Platform\DocumentVault\DocumentState;
use App\Platform\DocumentVault\Models\Document;
use App\Platform\Outbox\Outbox;
use App\Platform\Outbox\OutboxClassification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Task 1 — AC5's certificate half: replace an issued certificate by
 * marking the incumbent `replaced` and inserting the NEXT version row
 * (version_number + 1, freshly `issued`), preserving the old row
 * untouched as history. Audited `CERTIFICATE_REPLACED` and emitting the
 * catalogued `certificate.replaced.v1` in the same transaction.
 *
 * The AC4 role gate runs first, and the new version's vault document
 * must pass the same `DocumentState::Accepted` check `IssueCertificate`
 * enforces — a replacement pointing at unusable content would be a
 * regression, not a fix.
 *
 * The `$subject` argument must identify the SAME subject the incumbent
 * row carries: replacement preserves the certificate's history, so the
 * next version cannot be re-targeted at a different subject. The
 * replacement row keeps the incumbent's type and subject, and its
 * reference is freshly generated (each version row carries its own
 * document number — AC7's uniqueness is per issuer+type, not per
 * lineage). The same narrow classifier as `IssueCertificate` translates
 * a collision into `CertificateReferenceCollisionException`; the
 * `QueryException` is deliberately not chained (its message interpolates
 * the INSERT bindings).
 */
final readonly class ReplaceCertificate
{
    public function __construct(
        private CertificateIssuerAuthorizer $authorizer = new CertificateIssuerAuthorizer,
    ) {}

    public function __invoke(
        Certificate $certificate,
        Model $subject,
        int|string $actorReference,
        string $actorRole,
        ?string $documentId,
        ?string $reason = null,
        AuditSource $auditSource = AuditSource::Panel,
    ): Certificate {
        $this->authorizer->assertCanIssue($actorRole);

        try {
            return $this->record(
                $certificate,
                $subject,
                $actorReference,
                $actorRole,
                $documentId,
                $reason,
                $auditSource,
            );
        } catch (QueryException $exception) {
            if (! $this->isDuplicateReference($exception)) {
                throw $exception;
            }

            throw CertificateReferenceCollisionException::forIssuerAndType(
                (string) $actorReference,
                CertificateType::from((string) $certificate->type)->value,
            );
        }
    }

    private function record(
        Certificate $certificate,
        Model $subject,
        int|string $actorReference,
        string $actorRole,
        ?string $documentId,
        ?string $reason,
        AuditSource $auditSource,
    ): Certificate {
        return Audit::wrap(
            mutation: function () use (
                $certificate,
                $subject,
                $actorReference,
                $actorRole,
                $documentId,
            ): Certificate {
                $current = Certificate::query()->lockForUpdate()->findOrFail($certificate->getKey());

                if ($current->status() !== CertificateStatus::Issued) {
                    throw new InvalidArgumentException(
                        "certificates row [{$current->getKey()}] is [{$current->status}]; only an issued certificate can be replaced."
                    );
                }

                if (
                    $subject->getMorphClass() !== $current->subject_type
                    || (string) $subject->getKey() !== $current->subject_id
                ) {
                    throw new InvalidArgumentException(
                        'A certificate replacement must keep the incumbent certificate\'s subject; the history would otherwise be re-targeted.'
                    );
                }

                $document = null;

                if ($documentId !== null) {
                    /** @var Document|null $document */
                    $document = Document::query()->find($documentId);

                    if ($document === null || $document->state !== DocumentState::Accepted) {
                        throw new InvalidArgumentException(
                            'A certificate can only reference an accepted vault document (DocumentState::Accepted); the referenced document is missing, quarantined, scanning, or rejected.'
                        );
                    }
                }

                $current->status = CertificateStatus::Replaced->value;
                $current->save();

                if ($certificate !== $current) {
                    $certificate->setRawAttributes($current->getAttributes(), true);
                }

                $replacement = Certificate::query()->create([
                    'reference' => 'CERT-'.Str::upper(Str::random(8)),
                    'type' => CertificateType::from((string) $current->type)->value,
                    'version_number' => $current->version_number + 1,
                    'status' => CertificateStatus::Issued->value,
                    'subject_type' => $current->subject_type,
                    'subject_id' => $current->subject_id,
                    'issued_by_ref' => (string) $actorReference,
                    'issued_by_role' => $actorRole,
                    'effective_at' => now(),
                    'document_id' => $document?->getKey(),
                ]);

                $this->emitReplaced($replacement, $current);

                return $replacement;
            },
            action: AgreementCertificateAuditActions::CERTIFICATE_REPLACED,
            subject: fn (Certificate $replacement): AuditSubject => new AuditSubject(
                'certificate',
                $replacement->getKey(),
                $replacement->version_number,
            ),
            outcome: AuditOutcome::Allowed,
            actorRef: $actorReference,
            actorRole: $actorRole,
            source: $auditSource,
            reason: $reason,
            correlationId: app(CorrelationContext::class)->current()?->value,
        );
    }

    /**
     * `event-catalog.md:23` — `certificate.replaced.v1`, "Preserves
     * previous version": the payload names the replaced incumbent so a
     * consumer can follow the lineage, plus the new row's own
     * identifiers. References only, never restricted content.
     */
    private function emitReplaced(Certificate $replacement, Certificate $superseded): void
    {
        Outbox::record(
            eventName: 'certificate.replaced.v1',
            eventVersion: 1,
            aggregateType: 'certificate',
            aggregateId: $replacement->getKey(),
            data: [
                'certificate_id' => $replacement->getKey(),
                'superseded_certificate_id' => $superseded->getKey(),
                'type' => $replacement->type,
                'version_number' => $replacement->version_number,
                'reference' => $replacement->reference,
                'effective_at' => $replacement->effective_at?->toIso8601String(),
                'subject_type' => $replacement->subject_type,
                'subject_id' => $replacement->subject_id,
            ],
            classification: OutboxClassification::Internal,
            idempotencyKey: "certificate:{$replacement->getKey()}",
        );
    }

    private function isDuplicateReference(QueryException $exception): bool
    {
        $message = strtolower($exception->getMessage());

        if (str_contains($message, 'certificates_issuer_type_reference_unique')) {
            return true;
        }

        return str_contains($message, 'unique')
            && str_contains($message, 'certificates.reference');
    }
}
