<?php

declare(strict_types=1);

namespace App\Domain\AgreementCertificate\Exceptions;

use DomainException;

/**
 * The eligibility refusal for `Actions\IssueCertificate` — the honest
 * "no certificate" outcome when `CertificateEligibilityPolicy` evaluates
 * the certificate type's rule against the subject and the rule is not
 * met. Nothing is written: the refusal happens before the audit/outbox
 * transaction opens.
 */
final class CertificateEligibilityNotMetException extends DomainException
{
    public static function forTypeAndSubject(string $type, string $subjectType, string $subjectId): self
    {
        return new self(
            "Certificate type [{$type}] is not eligible for subject [{$subjectType}:{$subjectId}]; eligibility rules are evaluated against domain state."
        );
    }
}
