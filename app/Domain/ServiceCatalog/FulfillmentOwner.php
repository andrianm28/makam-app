<?php

declare(strict_types=1);

namespace App\Domain\ServiceCatalog;

use InvalidArgumentException;

/**
 * The closed list of fulfillment-owner values — `docs/product/
 * service-catalog.md` "Catalog rules": "Every service declares fulfillment
 * owner: platform, cemetery operator, or vendor." Plain string column with
 * application-layer validation, not a Postgres enum type — see
 * `ServiceCode`'s own doc block for the established convention this mirrors.
 *
 * Used by both `service_definitions.fulfillment_owner` (the catalogue-wide
 * default, left `null`/unset at seed time — see the seed migration's own
 * doc block for why) and `service_package_items.fulfillment_owner` (the
 * per-item value an admin declares when authoring a package version, per
 * `package-and-service-bundles/requirements.md` AC1 and AC7).
 */
final class FulfillmentOwner
{
    public const string PLATFORM = 'platform';

    public const string CEMETERY_OPERATOR = 'cemetery_operator';

    public const string VENDOR = 'vendor';

    /**
     * @var list<string>
     */
    public const array KNOWN_OWNERS = [
        self::PLATFORM,
        self::CEMETERY_OPERATOR,
        self::VENDOR,
    ];

    public static function isKnown(string $owner): bool
    {
        return in_array($owner, self::KNOWN_OWNERS, true);
    }

    /**
     * @throws InvalidArgumentException when `$owner` is not one of
     *                                  `self::KNOWN_OWNERS`.
     */
    public static function assertKnown(string $owner): void
    {
        if (! self::isKnown($owner)) {
            throw new InvalidArgumentException(
                "Unknown fulfillment owner [{$owner}]. Known owners: ".implode(', ', self::KNOWN_OWNERS).'.'
            );
        }
    }
}
