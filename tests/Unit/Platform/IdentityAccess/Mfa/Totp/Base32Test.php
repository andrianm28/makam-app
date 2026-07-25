<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\IdentityAccess\Mfa\Totp;

use App\Platform\IdentityAccess\Mfa\Totp\Base32;
use Tests\TestCase;

/**
 * RFC 4648 §10's own published Base32 test vectors, asserted exactly. Pure
 * PHP, no database — this is an encoding, not a cryptographic primitive,
 * but TOTP secrets are stored/displayed in Base32, so a wrong codec here
 * would corrupt every secret a user manually enters into an authenticator
 * app.
 */
final class Base32Test extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function rfc4648VectorsProvider(): array
    {
        // RFC 4648 §10, "Test Vectors".
        return [
            'empty string' => ['', ''],
            'f' => ['f', 'MY======'],
            'fo' => ['fo', 'MZXQ===='],
            'foo' => ['foo', 'MZXW6==='],
            'foob' => ['foob', 'MZXW6YQ='],
            'fooba' => ['fooba', 'MZXW6YTB'],
            'foobar' => ['foobar', 'MZXW6YTBOI======'],
        ];
    }

    /**
     * @dataProvider rfc4648VectorsProvider
     */
    public function test_encode_matches_rfc4648_vectors(string $input, string $expected): void
    {
        $this->assertSame($expected, Base32::encode($input));
    }

    /**
     * @dataProvider rfc4648VectorsProvider
     */
    public function test_decode_matches_rfc4648_vectors(string $input, string $encoded): void
    {
        $this->assertSame($input, Base32::decode($encoded));
    }

    public function test_decode_tolerates_missing_padding_and_lowercase(): void
    {
        $this->assertSame('foo', Base32::decode('mzxw6'));
    }

    public function test_generated_secret_round_trips_through_encode_and_decode(): void
    {
        $raw = random_bytes(20);

        $this->assertSame($raw, Base32::decode(Base32::encode($raw)));
    }
}
