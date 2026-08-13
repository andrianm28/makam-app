<?php

declare(strict_types=1);

namespace App\Domain\Booking;

/**
 * The closed list of payment method values for Step 8 —
 * `docs/product/booking-wizard-fields.md` §Step 8 ("Online mode" vs
 * "Manual fallback mode"). Plain string column with application-layer
 * validation, not a Postgres enum type — same established convention
 * as `BookingServiceType` and `BookingRelationshipCode`.
 */
final class BookingPaymentMethod
{
    public const string ONLINE = 'ONLINE';

    public const string MANUAL = 'MANUAL';

    /**
     * @var list<string>
     */
    public const array KNOWN_CODES = [
        self::ONLINE,
        self::MANUAL,
    ];

    public static function isKnown(string $code): bool
    {
        return in_array($code, self::KNOWN_CODES, true);
    }

    /**
     * Customer-facing Indonesian label. A confirmation screen showing
     * "MANUAL" tells the reader nothing; "Transfer Manual" does.
     */
    public static function label(?string $code): string
    {
        return match ($code) {
            self::ONLINE => 'Pembayaran Online',
            self::MANUAL => 'Transfer Manual',
            default => '—',
        };
    }

    public static function assertKnown(string $code): void
    {
        if (! self::isKnown($code)) {
            throw new \InvalidArgumentException(
                "Unknown payment method [{$code}]. Known methods: ".implode(', ', self::KNOWN_CODES).'.'
            );
        }
    }
}
