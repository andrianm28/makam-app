<?php

declare(strict_types=1);

namespace App\Domain\AgreementCertificate;

/**
 * The closed set of lifecycle states for a `certificates` row (one row
 * per certificate VERSION). Values mirror the `certificates.status`
 * PostgreSQL CHECK constraint in
 * `2026_08_17_100010_create_certificates_table.php`.
 *
 * `replaced` marks the incumbent when `ReplaceCertificate` writes the
 * next version row — the old row is never deleted or rewritten (AC5).
 */
enum CertificateStatus: string
{
    case Draft = 'draft';

    case Issued = 'issued';

    case Revoked = 'revoked';

    case Replaced = 'replaced';
}
