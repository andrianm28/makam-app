<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\IdentityAccess\Mfa\Totp;

use App\Platform\IdentityAccess\Mfa\Totp\Hotp;
use Tests\TestCase;

/**
 * RFC 4226 Appendix D's own published HOTP test vectors ("Table 1" /
 * "HOTP Values for the secret 'ASCII string 12345678901234567890'"): the
 * 20-byte ASCII secret and the 6-digit HOTP values it produces for
 * counters 0-9.
 *
 * This directly verifies the digit count this module actually uses in
 * production (6 — see `Totp`'s class-level doc block for why) against an
 * official source, independent of the 8-digit RFC 6238 vectors verified in
 * `TotpRfc6238VectorsTest`. Both tests exercise the exact same
 * `Hotp::generate()` dynamic-truncation code path; only the final decimal
 * modulus (`% 10**6` vs `% 10**8`) differs.
 */
final class HotpRfc4226VectorsTest extends TestCase
{
    private const string RFC_SECRET = '12345678901234567890';

    /**
     * @return array<string, array{0: int, 1: string}>
     */
    public static function rfc4226VectorsProvider(): array
    {
        // RFC 4226 Appendix D.
        return [
            'counter 0' => [0, '755224'],
            'counter 1' => [1, '287082'],
            'counter 2' => [2, '359152'],
            'counter 3' => [3, '969429'],
            'counter 4' => [4, '338314'],
            'counter 5' => [5, '254676'],
            'counter 6' => [6, '287922'],
            'counter 7' => [7, '162583'],
            'counter 8' => [8, '399871'],
            'counter 9' => [9, '520489'],
        ];
    }

    /**
     * @dataProvider rfc4226VectorsProvider
     */
    public function test_hotp_matches_rfc4226_appendix_d_vectors(int $counter, string $expected): void
    {
        $this->assertSame($expected, Hotp::generate(self::RFC_SECRET, $counter, 6));
    }
}
