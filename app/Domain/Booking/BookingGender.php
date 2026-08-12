<?php

declare(strict_types=1);

namespace App\Domain\Booking;

/**
 * The closed list of gender values for Step 7 —
 * `docs/product/booking-wizard-fields.md` §Step 7 ("gender/other
 * administrative attributes only when required"). Plain string column
 * with application-layer validation, not a Postgres enum type — same
 * established convention as `BookingServiceType` and
 * `BookingRelationshipCode`.
 */
final class BookingGender
{
    public const string LAKI_LAKI = 'LAKI_LAKI';

    public const string PEREMPUAN = 'PEREMPUAN';

    /**
     * @var list<string>
     */
    public const array KNOWN_CODES = [
        self::LAKI_LAKI,
        self::PEREMPUAN,
    ];

    public static function isKnown(string $code): bool
    {
        return in_array($code, self::KNOWN_CODES, true);
    }

    public static function assertKnown(string $code): void
    {
        if (! self::isKnown($code)) {
            throw new \InvalidArgumentException(
                "Unknown gender code [{$code}]. Known codes: ".implode(', ', self::KNOWN_CODES).'.'
            );
        }
    }
}
