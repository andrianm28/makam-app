<?php

declare(strict_types=1);

namespace App\Domain\ServiceCatalog;

use InvalidArgumentException;

/**
 * The closed list of `service_definitions.category` values —
 * `service-catalog.md`'s own two-table split: "Basic services" and
 * "Additional services". Plain string column with application-layer
 * validation, not a Postgres enum type — see `ServiceCode`'s own doc block
 * for the established convention this mirrors.
 */
final class ServiceCategory
{
    public const string BASIC = 'basic';

    public const string ADDITIONAL = 'additional';

    /**
     * @var list<string>
     */
    public const array KNOWN_CATEGORIES = [
        self::BASIC,
        self::ADDITIONAL,
    ];

    public static function isKnown(string $category): bool
    {
        return in_array($category, self::KNOWN_CATEGORIES, true);
    }

    /**
     * @throws InvalidArgumentException when `$category` is not one of
     *                                  `self::KNOWN_CATEGORIES`.
     */
    public static function assertKnown(string $category): void
    {
        if (! self::isKnown($category)) {
            throw new InvalidArgumentException(
                "Unknown service category [{$category}]. Known categories: ".implode(', ', self::KNOWN_CATEGORIES).'.'
            );
        }
    }
}
