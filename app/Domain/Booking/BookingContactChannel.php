<?php

declare(strict_types=1);

namespace App\Domain\Booking;

/**
 * The closed list of preferred contact channels for Step 6 —
 * `docs/product/booking-wizard-fields.md` §Step 6 ("preferred contact
 * channel"). Plain string column with application-layer validation, not
 * a Postgres enum type — same established convention as
 * `BookingServiceType` and `BookingRelationshipCode`.
 */
final class BookingContactChannel
{
    public const string WHATSAPP = 'WHATSAPP';

    public const string TELEPON = 'TELEPON';

    public const string EMAIL = 'EMAIL';

    /**
     * @var list<string>
     */
    public const array KNOWN_CODES = [
        self::WHATSAPP,
        self::TELEPON,
        self::EMAIL,
    ];

    public static function isKnown(string $code): bool
    {
        return in_array($code, self::KNOWN_CODES, true);
    }

    /**
     * Customer-facing Indonesian label. Falls back to a neutral phrase for a
     * null or unrecognised code so a confirmation screen never has to print
     * a raw enum token at a bereaved reader.
     */
    public static function label(?string $code): string
    {
        return match ($code) {
            self::WHATSAPP => 'WhatsApp',
            self::TELEPON => 'telepon',
            self::EMAIL => 'email',
            default => 'kontak yang Anda pilih',
        };
    }

    public static function assertKnown(string $code): void
    {
        if (! self::isKnown($code)) {
            throw new \InvalidArgumentException(
                "Unknown contact channel [{$code}]. Known channels: ".implode(', ', self::KNOWN_CODES).'.'
            );
        }
    }
}
