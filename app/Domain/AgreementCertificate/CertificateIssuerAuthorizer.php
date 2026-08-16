<?php

declare(strict_types=1);

namespace App\Domain\AgreementCertificate;

use App\Domain\AgreementCertificate\Exceptions\CertificateIssuerNotAuthorisedException;
use App\Platform\IdentityAccess\Roles\ActorRole;

/**
 * AC4's issuer-role gate, shared by the three issuing actions
 * (`IssueCertificate`, `RevokeCertificate`, `ReplaceCertificate`):
 * issuance, revocation, and replacement require `admin` or
 * `restricted_admin`. Any other role — including `operator` and
 * `finance`, which may VIEW certificates — is refused with
 * `CertificateIssuerNotAuthorisedException` before anything is written.
 *
 * The allowed set is written against `ActorRole`'s constants rather than
 * redeclared as literals, so the role vocabulary stays in one place.
 */
final class CertificateIssuerAuthorizer
{
    public function assertCanIssue(string $issuerRole): void
    {
        if (! in_array($issuerRole, [ActorRole::ADMIN, ActorRole::RESTRICTED_ADMIN], true)) {
            throw CertificateIssuerNotAuthorisedException::forRole($issuerRole);
        }
    }
}
