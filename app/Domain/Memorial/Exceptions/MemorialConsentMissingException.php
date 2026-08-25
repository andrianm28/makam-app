<?php

declare(strict_types=1);

namespace App\Domain\Memorial\Exceptions;

use InvalidArgumentException;

/**
 * An editor grant was attempted without consent evidence —
 * `.kiro/specs/memorial-and-qr/requirements.md` AC1: "THE SYSTEM SHALL
 * require authority/consent evidence before granting editor access."
 *
 * `GrantMemorialEditor` refuses a blank `$consentEvidenceRef` with this
 * exception BEFORE any row is written: a family member's access to a
 * memorial profile is a privacy-sensitive grant, and it must never
 * happen without the documented evidence reference that later audit can
 * point to.
 */
final class MemorialConsentMissingException extends InvalidArgumentException
{
    public static function forGrant(int|string $profileId, int|string $actorId): self
    {
        return new self(
            "Cannot grant memorial editor [{$actorId}] on profile [{$profileId}]: consent evidence is required."
        );
    }
}
