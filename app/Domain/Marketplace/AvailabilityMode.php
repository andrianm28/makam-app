<?php

declare(strict_types=1);

namespace App\Domain\Marketplace;

use InvalidArgumentException;

/**
 * How a listing's availability is determined — a counted stock level, built
 * on demand after ordering, or booked against `vendor_availability`.
 * `stock_quantity` is meaningful only for `STOCKED`. Plain-string-class
 * convention, matching `ProductCode` and `EvidenceRequirement`.
 */
final class AvailabilityMode
{
    public const string STOCKED = 'STOCKED';

    public const string MADE_TO_ORDER = 'MADE_TO_ORDER';

    public const string SCHEDULED = 'SCHEDULED';

    /** @var list<string> */
    public const array KNOWN = [self::STOCKED, self::MADE_TO_ORDER, self::SCHEDULED];

    public static function isKnown(string $value): bool
    {
        return in_array($value, self::KNOWN, true);
    }

    public static function assertKnown(string $value): void
    {
        if (! self::isKnown($value)) {
            throw new InvalidArgumentException(
                "Unknown availability mode [{$value}]. Known: ".implode(', ', self::KNOWN).'.'
            );
        }
    }
}
