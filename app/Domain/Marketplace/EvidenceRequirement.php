<?php

declare(strict_types=1);

namespace App\Domain\Marketplace;

use InvalidArgumentException;

/**
 * What a vendor must upload to close out a fulfilment — `marketplace-catalog.md`
 * §"Required product data" names "evidence requirement" without enumerating
 * values, so these three are the minimum needed to express "nothing",
 * "a photo", and "a signed document". Plain-string-class convention, matching
 * `ProductCode` and `VendorProcessingStatus`.
 */
final class EvidenceRequirement
{
    public const string NONE = 'NONE';

    public const string PHOTO = 'PHOTO';

    public const string DOCUMENT = 'DOCUMENT';

    /** @var list<string> */
    public const array KNOWN = [self::NONE, self::PHOTO, self::DOCUMENT];

    public static function isKnown(string $value): bool
    {
        return in_array($value, self::KNOWN, true);
    }

    public static function assertKnown(string $value): void
    {
        if (! self::isKnown($value)) {
            throw new InvalidArgumentException(
                "Unknown evidence requirement [{$value}]. Known: ".implode(', ', self::KNOWN).'.'
            );
        }
    }
}
