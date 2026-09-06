<?php

declare(strict_types=1);

namespace App\Domain\Memorial\Exceptions;

use RuntimeException;

/**
 * Thrown ONLY after `ResolveMemorialQr` has already succeeded — a
 * throttled caller already holds a token that resolves to a real,
 * visible, published memorial, so this is safe to distinguish from
 * `MemorialNotVisibleException` without creating a second enumeration
 * oracle (`docs/superpowers/specs/2026-09-05-memorial-visit-checkin-design.md`
 * §4.3).
 */
final class MemorialVisitCheckInThrottledException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $retryAfterSeconds,
    ) {
        parent::__construct($message);
    }

    public static function forToken(string $token, int $retryAfterSeconds): self
    {
        return new self("Too many visit check-ins for this token; retry in {$retryAfterSeconds}s.", $retryAfterSeconds);
    }
}
