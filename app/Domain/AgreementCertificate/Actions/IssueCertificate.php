<?php

declare(strict_types=1);

namespace App\Domain\AgreementCertificate\Actions;

use App\Domain\AgreementCertificate\AgreementCertificateAuditActions;
use App\Domain\AgreementCertificate\CertificateEligibilityPolicy;
use App\Domain\AgreementCertificate\CertificateIssuerAuthorizer;
use App\Domain\AgreementCertificate\CertificateStatus;
use App\Domain\AgreementCertificate\CertificateType;
use App\Domain\AgreementCertificate\Exceptions\CertificateEligibilityNotMetException;
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
 * Task 1 — certificate issuance: the AC4 role gate, the AC3 eligibility
 * evaluation, AC7's reference generation with the database backstop and
 * its narrow classifier, the vault-document Accepted check, the
 * `CERTIFICATE_ISSUED` audit, and the catalogued `certificate.issued.v1`
 * outbox event — all inside one `Audit::wrap()` transaction.
 *
 * Ordering matters and is pinned by the tests:
 *
 *  1. Role gate FIRST (`CertificateIssuerAuthorizer`): an operator or
 *     any other non-issuer role is refused before eligibility or the
 *     transaction is even reached — nothing is written.
 *  2. Eligibility (`CertificateEligibilityPolicy`): rule not met →
 *     `CertificateEligibilityNotMetException`, nothing written.
 *  3. Inside the transaction: the document reference (when provided)
 *     must resolve to a vault `Document` in `DocumentState::Accepted` —
 *     a Quarantined, Scanning, or Rejected document is an HONEST
 *     refusal (`InvalidArgumentException`), never an issued certificate
 *     pointing at unusable content. A missing document id row is the
 *     same refusal.
 *  4. The reference: `'CERT-'.Str::upper(Str::random(8))` per issuer and
 *     type, with `certificates_issuer_type_reference_unique` (AC7) as
 *     the database backstop. A collision is translated by the narrow
 *     classifier below into `CertificateReferenceCollisionException` —
 *     the `OrderAlreadyPaidException` pattern, deliberately NOT chaining
 *     the raw `QueryException` (its message interpolates the INSERT
 *     bindings; see that exception's doc block).
 *  5. Audit `CERTIFICATE_ISSUED` + outbox `certificate.issued.v1`
 *     (idempotency key `certificate:{$id}`) in the same transaction as
 *     the row.
 *
 * The trailing `$reference` parameter is a deterministic test seam for
 * the collision path: the pinned interface's six positions are
 * unchanged, and production callers never pass it. When provided, the
 * reference is used verbatim instead of a random one, so the
 * (issuer, type, reference) uniqueness backstop is exercisable.
 *
 * Version numbering is append semantics: the new row's version_number is
 * the subject+type's maximum plus one (1 for a first issuance), so a
 * second IssueCertificate call for the same subject appends a new
 * version instead of colliding on `certificates_subject_type_version_unique`;
 * the sanctioned re-issuance path remains `ReplaceCertificate`.
 */
final readonly class IssueCertificate
{
    public function __construct(
        private CertificateIssuerAuthorizer $authorizer = new CertificateIssuerAuthorizer,
        private CertificateEligibilityPolicy $policy = new CertificateEligibilityPolicy,
    ) {}

    public function __invoke(
        CertificateType $type,
        Model $subject,
        int|string $issuerReference,
        string $issuerRole,
        ?string $documentId,
        ?string $reason = null,
        AuditSource $auditSource = AuditSource::Panel,
        ?string $reference = null,
    ): Certificate {
        $this->authorizer->assertCanIssue($issuerRole);

        if (! $this->policy->eligibleFor($type->value, $subject)) {
            throw CertificateEligibilityNotMetException::forTypeAndSubject(
                $type->value,
                $subject->getMorphClass(),
                (string) $subject->getKey(),
            );
        }

        try {
            return $this->record(
                $type,
                $subject,
                $issuerReference,
                $issuerRole,
                $documentId,
                $reason,
                $auditSource,
                $reference,
            );
        } catch (QueryException $exception) {
            if (! $this->isDuplicateReference($exception)) {
                throw $exception;
            }

            // Deliberately not chained as `$previous` — see
            // `CertificateReferenceCollisionException`'s doc block: the
            // original message carries the interpolated INSERT bindings.
            throw CertificateReferenceCollisionException::forIssuerAndType(
                (string) $issuerReference,
                $type->value,
            );
        }
    }

    private function record(
        CertificateType $type,
        Model $subject,
        int|string $issuerReference,
        string $issuerRole,
        ?string $documentId,
        ?string $reason,
        AuditSource $auditSource,
        ?string $reference,
    ): Certificate {
        return Audit::wrap(
            mutation: function () use (
                $type,
                $subject,
                $issuerReference,
                $issuerRole,
                $documentId,
                $reference,
            ): Certificate {
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

                $certificate = Certificate::query()->create([
                    'reference' => $reference ?? 'CERT-'.Str::upper(Str::random(8)),
                    'type' => $type->value,
                    'version_number' => $this->nextVersionNumber($type, $subject),
                    'status' => CertificateStatus::Issued->value,
                    'subject_type' => $subject->getMorphClass(),
                    'subject_id' => (string) $subject->getKey(),
                    'issued_by_ref' => (string) $issuerReference,
                    'issued_by_role' => $issuerRole,
                    'effective_at' => now(),
                    'document_id' => $document?->getKey(),
                ]);

                $this->emitIssued($certificate);

                return $certificate;
            },
            action: AgreementCertificateAuditActions::CERTIFICATE_ISSUED,
            subject: fn (Certificate $certificate): AuditSubject => new AuditSubject(
                'certificate',
                $certificate->getKey(),
                $certificate->version_number,
            ),
            outcome: AuditOutcome::Allowed,
            actorRef: $issuerReference,
            actorRole: $issuerRole,
            source: $auditSource,
            reason: $reason,
            correlationId: app(CorrelationContext::class)->current()?->value,
        );
    }

    private function nextVersionNumber(CertificateType $type, Model $subject): int
    {
        $max = Certificate::query()
            ->where('type', $type->value)
            ->where('subject_type', $subject->getMorphClass())
            ->where('subject_id', (string) $subject->getKey())
            ->max('version_number');

        return $max === null ? 1 : (int) $max + 1;
    }

    /**
     * `event-catalog.md:22` — `certificate.issued.v1`, "Unique issuer
     * number". References only: the certificate's own identifiers and
     * its effective date, never restricted content.
     */
    private function emitIssued(Certificate $certificate): void
    {
        Outbox::record(
            eventName: 'certificate.issued.v1',
            eventVersion: 1,
            aggregateType: 'certificate',
            aggregateId: $certificate->getKey(),
            data: [
                'certificate_id' => $certificate->getKey(),
                'type' => $certificate->type,
                'version_number' => $certificate->version_number,
                'reference' => $certificate->reference,
                'effective_at' => $certificate->effective_at?->toIso8601String(),
                'subject_type' => $certificate->subject_type,
                'subject_id' => $certificate->subject_id,
            ],
            classification: OutboxClassification::Internal,
            idempotencyKey: "certificate:{$certificate->getKey()}",
        );
    }

    /**
     * Same detection style as
     * `RecordOrderStatusChange::isDuplicatePaidEvent` and deliberately
     * narrow for the reason that method documents at length:
     * `QueryException`'s message always echoes the INSERT's own column
     * list, so matching a BARE column name would classify a NOT NULL or
     * length violation on this table as a duplicate reference.
     *
     * PostgreSQL names the failing index directly
     * (`certificates_issuer_type_reference_unique`) and is matched
     * first. SQLite reports the QUALIFIED `table.column` form, which
     * appears only in its constraint description and never in the
     * unqualified INSERT column list — and `certificates.reference` only
     * appears in the description of the AC7 index, never in the
     * `(subject_type, subject_id, type, version_number)` version index.
     */
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
