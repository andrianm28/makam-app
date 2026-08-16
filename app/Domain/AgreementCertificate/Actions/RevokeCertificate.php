<?php

declare(strict_types=1);

namespace App\Domain\AgreementCertificate\Actions;

use App\Domain\AgreementCertificate\AgreementCertificateAuditActions;
use App\Domain\AgreementCertificate\CertificateIssuerAuthorizer;
use App\Domain\AgreementCertificate\CertificateStatus;
use App\Domain\AgreementCertificate\Models\Certificate;
use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditOutcome;
use App\Platform\Audit\AuditSource;
use App\Platform\Audit\AuditSubject;
use App\Platform\Correlation\CorrelationContext;
use InvalidArgumentException;

/**
 * Task 1 — revocation: issued -> revoked, audited `CERTIFICATE_REVOKED`.
 * No outbox event is emitted: the catalog carries no certificate-revoked
 * event, and none is invented — the audit row is the record.
 *
 * The AC4 role gate runs first (`CertificateIssuerAuthorizer`); a
 * non-issuer role is refused before anything is written. A revocation is
 * destructive, so a non-blank reason is mandatory and is recorded on the
 * audit row — enforced at the action (the plan pins that this module's
 * actions are NOT added to `SensitiveActions::ACTIONS`; the mandatory
 * reason is therefore a local control, not the platform's global list).
 */
final readonly class RevokeCertificate
{
    public function __construct(
        private CertificateIssuerAuthorizer $authorizer = new CertificateIssuerAuthorizer,
    ) {}

    public function __invoke(
        Certificate $certificate,
        int|string $actorReference,
        string $actorRole,
        string $reason,
        AuditSource $auditSource = AuditSource::Panel,
    ): Certificate {
        $this->authorizer->assertCanIssue($actorRole);

        if (Audit::reasonIsBlank($reason)) {
            throw new InvalidArgumentException('Revoking a certificate requires a non-blank reason.');
        }

        return Audit::wrap(
            mutation: function () use ($certificate): Certificate {
                $current = Certificate::query()->lockForUpdate()->findOrFail($certificate->getKey());

                if ($current->status() !== CertificateStatus::Issued) {
                    throw new InvalidArgumentException(
                        "certificates row [{$current->getKey()}] is [{$current->status}]; only an issued certificate can be revoked."
                    );
                }

                $current->status = CertificateStatus::Revoked->value;
                $current->save();

                if ($certificate !== $current) {
                    $certificate->setRawAttributes($current->getAttributes(), true);
                }

                return $certificate;
            },
            action: AgreementCertificateAuditActions::CERTIFICATE_REVOKED,
            subject: fn (Certificate $revoked): AuditSubject => new AuditSubject(
                'certificate',
                $revoked->getKey(),
                $revoked->version_number,
            ),
            outcome: AuditOutcome::Allowed,
            actorRef: $actorReference,
            actorRole: $actorRole,
            source: $auditSource,
            reason: $reason,
            correlationId: app(CorrelationContext::class)->current()?->value,
        );
    }
}
