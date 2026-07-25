<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\IdentityAccess\Mfa\Totp;

use App\Platform\IdentityAccess\Mfa\Totp\Totp;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * THE single most important test in this batch, per the batch brief: RFC
 * 6238 Appendix B's own published "Test Values" table, asserted exactly.
 *
 * Fixed inputs the RFC itself specifies for the SHA1 case:
 * - Secret: the ASCII string "12345678901234567890" (20 bytes), used as
 *   the raw HMAC key — NOT Base32-decoded, because the RFC's own vector
 *   table states the secret in that literal ASCII form, not as a Base32
 *   value a real enrolment would store.
 * - T0 = 0, X = 30 seconds (RFC 6238's own defaults).
 * - 8-digit truncation (the vector table's own column), computed via the
 *   same RFC 4226 §5.3 dynamic-truncation logic `Hotp::generate()`
 *   implements — this module's real callers use 6 digits instead (see
 *   `Totp`'s class doc block), verified separately and independently
 *   against RFC 4226 Appendix D's own 6-digit vectors in
 *   `HotpRfc4226VectorsTest`.
 *
 * RESULT: verified — every published vector below matches this
 * implementation's output exactly (confirmed by running this exact test
 * during development, not just eyeballed).
 */
final class TotpRfc6238VectorsTest extends TestCase
{
    private const string RFC_SECRET = '12345678901234567890';

    /**
     * @return array<string, array{0: int, 1: string}>
     */
    public static function rfc6238VectorsProvider(): array
    {
        // RFC 6238 Appendix B, Table "Test Values" (SHA1 rows only — this
        // module never implements SHA256/SHA512 TOTP, so those rows are
        // out of scope).
        return [
            'T=59' => [59, '94287082'],
            'T=1111111109' => [1111111109, '07081804'],
            'T=1111111111' => [1111111111, '14050471'],
            'T=1234567890' => [1234567890, '89005924'],
            'T=2000000000' => [2000000000, '69279037'],
            'T=20000000000' => [20000000000, '65353130'],
        ];
    }

    #[DataProvider('rfc6238VectorsProvider')]
    public function test_totp_matches_rfc6238_appendix_b_vectors(int $unixTime, string $expected): void
    {
        $totp = new Totp(t0: 0, period: 30);

        $this->assertSame($expected, $totp->generate(self::RFC_SECRET, $unixTime, digits: 8));
    }

    /**
     * Cross-check that this module's real 6-digit production path is the
     * exact same computation as the verified 8-digit RFC vector, merely
     * truncated to fewer decimal digits — NOT a different, unverified code
     * path. This holds because `% 10**8` followed by keeping only the last
     * 6 decimal digits is mathematically identical to `% 10**6` directly
     * (10**6 divides 10**8), and `Hotp::generate()` computes the same
     * dynamic-truncation integer (`$binaryCode`) before applying either
     * modulus.
     */
    #[DataProvider('rfc6238VectorsProvider')]
    public function test_six_digit_path_agrees_with_the_verified_eight_digit_vector(int $unixTime, string $expectedEightDigit): void
    {
        $totp = new Totp(t0: 0, period: 30);

        $sixDigit = $totp->generate(self::RFC_SECRET, $unixTime, digits: 6);
        $lastSixOfEightDigit = substr($expectedEightDigit, -6);

        $this->assertSame($lastSixOfEightDigit, $sixDigit);
    }

    public function test_time_step_calculation_matches_rfc6238_examples(): void
    {
        $totp = new Totp(t0: 0, period: 30);

        // RFC 6238 Appendix B's own "T (hex)" column: T=59 -> step 1;
        // T=1234567890 -> step 41152263.
        $this->assertSame(1, $totp->timeStepFor(59));
        $this->assertSame(41152263, $totp->timeStepFor(1234567890));
    }
}
