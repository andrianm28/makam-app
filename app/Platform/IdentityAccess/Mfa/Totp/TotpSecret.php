<?php

declare(strict_types=1);

namespace App\Platform\IdentityAccess\Mfa\Totp;

/**
 * Generates a fresh TOTP secret. RFC 4226 §4 R6 recommends a shared secret
 * of at least 128 bits, "160 bits is recommended" — this uses exactly 160
 * bits (20 raw bytes), matching both the RFC's own recommendation AND the
 * secret length RFC 6238 Appendix B's SHA1 test vectors use (a 20-byte
 * ASCII secret), so this module's "real" secret length and its verified
 * test-vector length are the same order of magnitude.
 *
 * `random_bytes()` (CSPRNG, not `rand()`/`mt_rand()`) per PHP's own manual
 * guidance for security-sensitive random data.
 */
final class TotpSecret
{
    private const int LENGTH_BYTES = 20;

    /**
     * Base32-encoded, ready for storage in `mfa_enrolments.secret` (via the
     * `encrypted` Eloquent cast) and for direct use in an `otpauth://` URI
     * or manual-entry display — Base32 is the conventional TOTP secret
     * encoding for both.
     */
    public static function generate(): string
    {
        return Base32::encode(random_bytes(self::LENGTH_BYTES));
    }
}
