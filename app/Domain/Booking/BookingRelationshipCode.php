<?php

declare(strict_types=1);

namespace App\Domain\Booking;

/**
 * The closed list of relationship values used in booking Steps 6 and 7 —
 * `docs/product/booking-wizard-fields.md` §Step 6 ("relationship to
 * deceased") and §Step 7 ("relationship to customer"). Plain string
 * column with application-layer validation, not a Postgres enum type —
 * same established convention as `BookingServiceType` and
 * `LaunchCityCode`.
 */
final class BookingRelationshipCode
{
    public const string PASANGAN = 'PASANGAN';

    public const string ANAK = 'ANAK';

    public const string ORANG_TUA = 'ORANG_TUA';

    public const string SAUDARA = 'SAUDARA';

    public const string LAINNYA = 'LAINNYA';

    /**
     * @var list<string>
     */
    public const array KNOWN_CODES = [
        self::PASANGAN,
        self::ANAK,
        self::ORANG_TUA,
        self::SAUDARA,
        self::LAINNYA,
    ];

    public static function isKnown(string $code): bool
    {
        return in_array($code, self::KNOWN_CODES, true);
    }

    public static function assertKnown(string $code): void
    {
        if (! self::isKnown($code)) {
            throw new \InvalidArgumentException(
                "Unknown relationship code [{$code}]. Known codes: ".implode(', ', self::KNOWN_CODES).'.'
            );
        }
    }
}
