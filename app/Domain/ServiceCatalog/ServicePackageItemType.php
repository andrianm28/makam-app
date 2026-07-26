<?php

declare(strict_types=1);

namespace App\Domain\ServiceCatalog;

use InvalidArgumentException;

/**
 * The closed list of `service_package_items.item_type` values —
 * `package-and-service-bundles/requirements.md` AC1: "the included,
 * optional, and excluded items" every package version must define. Plain
 * string column with application-layer validation, not a Postgres enum
 * type — see `App\Domain\ServiceCatalog\ServiceCode`'s own doc block for the
 * established convention this mirrors.
 */
final class ServicePackageItemType
{
    public const string INCLUDED = 'included';

    public const string OPTIONAL = 'optional';

    public const string EXCLUDED = 'excluded';

    /**
     * @var list<string>
     */
    public const array KNOWN_TYPES = [
        self::INCLUDED,
        self::OPTIONAL,
        self::EXCLUDED,
    ];

    public static function isKnown(string $type): bool
    {
        return in_array($type, self::KNOWN_TYPES, true);
    }

    /**
     * @throws InvalidArgumentException when `$type` is not one of
     *                                  `self::KNOWN_TYPES`.
     */
    public static function assertKnown(string $type): void
    {
        if (! self::isKnown($type)) {
            throw new InvalidArgumentException(
                "Unknown service package item type [{$type}]. Known types: ".implode(', ', self::KNOWN_TYPES).'.'
            );
        }
    }
}
