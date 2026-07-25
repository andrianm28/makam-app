<?php

declare(strict_types=1);

namespace App\Platform\IdentityAccess\Mfa\Totp;

/**
 * RFC 4226 HOTP: HMAC-SHA1 followed by §5.3's "dynamic truncation" (DT),
 * then a decimal modulus for the chosen digit count. This is the core
 * primitive `Totp` builds on (TOTP is HOTP with the counter derived from
 * time, RFC 6238 §1: "TOTP = HOTP(K, T)").
 *
 * Verified byte-for-byte against RFC 4226 Appendix D's own published
 * 6-digit test vectors (counters 0-9 against the RFC's fixed 20-byte ASCII
 * secret "12345678901234567890") in
 * `tests/Unit/Platform/IdentityAccess/Mfa/Totp/HotpRfc4226VectorsTest.php`,
 * and indirectly via RFC 6238 Appendix B's 8-digit vectors through `Totp`
 * itself (`TotpRfc6238VectorsTest`) — the same dynamic-truncation code path
 * runs for both, only the final `% 10**digits` modulus differs.
 */
final class Hotp
{
    /**
     * @param  string  $key  Raw (already Base32-decoded) HMAC key bytes.
     * @param  int  $counter  The moving factor — an 8-byte big-endian
     *                        unsigned integer per RFC 4226 §5.2.
     * @param  int  $digits  Output digit count. RFC 4226 recommends 6 or 8;
     *                       this module's own `Totp` picks 6 for real
     *                       enrolment/challenge use (see that class's doc
     *                       block), while the RFC 6238 Appendix B test
     *                       vectors themselves use 8 — this method accepts
     *                       either, unchanged, so both call sites share one
     *                       verified implementation.
     */
    public static function generate(string $key, int $counter, int $digits = 6): string
    {
        // RFC 4226 §5.2: "the counter value ... is 8 bytes ... converted
        // using big endian order." pack('J', ...) is an unsigned 64-bit
        // big-endian integer (available since PHP 5.6.3), matching that
        // exactly for every counter value this codebase will ever produce
        // (floor(unix time / 30) is nowhere near PHP_INT_MAX).
        $counterBytes = pack('J', $counter);

        $hash = hash_hmac('sha1', $counterBytes, $key, true);

        // RFC 4226 §5.3 "Dynamic Truncation" (DT): low-order 4 bits of the
        // last byte select a 4-byte window; the top bit of that window's
        // first byte is masked off (& 0x7f) so the resulting 31-bit integer
        // is never interpreted as negative.
        $offset = ord($hash[19]) & 0x0F;

        $binaryCode = ((ord($hash[$offset]) & 0x7F) << 24)
            | ((ord($hash[$offset + 1]) & 0xFF) << 16)
            | ((ord($hash[$offset + 2]) & 0xFF) << 8)
            | (ord($hash[$offset + 3]) & 0xFF);

        $code = $binaryCode % (10 ** $digits);

        return str_pad((string) $code, $digits, '0', STR_PAD_LEFT);
    }
}
