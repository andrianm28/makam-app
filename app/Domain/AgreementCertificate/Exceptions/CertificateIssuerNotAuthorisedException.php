<?php

declare(strict_types=1);

namespace App\Domain\AgreementCertificate\Exceptions;

use RuntimeException;

/**
 * AC4's issuer-role refusal: issuance, revocation, and replacement
 * require an authorised issuer role (`admin` / `restricted_admin` — see
 * `CertificateIssuerAuthorizer`). An operator, finance, or any other role
 * invoking an issuing action is refused before anything is written.
 */
final class CertificateIssuerNotAuthorisedException extends RuntimeException
{
    public static function forRole(string $role): self
    {
        return new self(
            "Actor role [{$role}] is not authorised to issue, revoke, or replace certificates."
        );
    }
}
