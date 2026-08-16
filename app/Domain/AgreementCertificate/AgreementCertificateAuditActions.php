<?php

declare(strict_types=1);

namespace App\Domain\AgreementCertificate;

/**
 * The audit action vocabulary for the AgreementCertificate domain —
 * written to `audit_events.action` by this module's Actions.
 *
 * The plan pins these six values and their exact spellings, and pins
 * that NONE of them is added to `SensitiveActions::ACTIONS` — the
 * platform's mandatory-reason list is deliberately not extended here
 * (revocation still requires a non-blank reason inside
 * `Actions\RevokeCertificate`, enforced at the action, not via the
 * global list).
 */
final class AgreementCertificateAuditActions
{
    public const string CERTIFICATE_ISSUED = 'CERTIFICATE_ISSUED';

    public const string CERTIFICATE_REVOKED = 'CERTIFICATE_REVOKED';

    public const string CERTIFICATE_REPLACED = 'CERTIFICATE_REPLACED';

    public const string AGREEMENT_CREATED = 'AGREEMENT_CREATED';

    public const string AGREEMENT_ACCEPTED = 'AGREEMENT_ACCEPTED';

    public const string AGREEMENT_SUPERSEDED = 'AGREEMENT_SUPERSEDED';
}
