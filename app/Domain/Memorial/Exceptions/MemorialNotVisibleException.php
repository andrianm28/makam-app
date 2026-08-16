<?php

declare(strict_types=1);

namespace App\Domain\Memorial\Exceptions;

use RuntimeException;

/**
 * The ONE exception for "this memorial is not visible to you" — thrown
 * by `App\Domain\Memorial\Actions\ResolveMemorialQr` for every denial
 * case, per `.kiro/specs/memorial-and-qr/requirements.md` AC5's negative
 * criterion ("never reveal which case applies") and design.md's Error
 * handling section ("revoked/rotated token responds identically to a
 * token that never existed").
 *
 * Gate-closed, unknown/revoked token, and privacy-denied are the SAME
 * class, by design: anything that let a caller distinguish them would
 * leak which tokens exist (a token that resolves to "gate closed" vs
 * one that resolves to "not found" is exactly the enumeration oracle
 * AC4/AC5 exist to close). The three factories are internal vocabulary
 * for the log message only — a handler renders the uniform
 * "memorial tidak tersedia" state regardless of which one fired.
 */
final class MemorialNotVisibleException extends RuntimeException
{
    public static function becauseGateClosed(): self
    {
        return new self('The memorial feature is not available.');
    }

    public static function becauseUnknownToken(): self
    {
        return new self('No memorial is available for this code.');
    }

    public static function becausePrivacy(string $privacyMode): self
    {
        return new self("This memorial is not visible under its current privacy mode [{$privacyMode}].");
    }
}
