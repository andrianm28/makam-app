<?php

declare(strict_types=1);

namespace App\Domain\ServiceCatalog;

use InvalidArgumentException;

/**
 * The closed list of `service_package_versions.status` values.
 *
 * ---------------------------------------------------------------------------
 * Two states, not three — a deliberate, minimal reading of AC2
 * ---------------------------------------------------------------------------
 * `package-and-service-bundles/requirements.md` AC2: "THE SYSTEM SHALL NOT
 * allow modification of a published package version." That is the entire
 * lifecycle this batch's scope needs: a version is authored as `draft`
 * (freely editable — items/evidence/substitution rows may be added, changed,
 * removed) and, exactly once, transitions to `published` (permanently
 * frozen from that point on — see `Models\ServicePackageVersion`'s own
 * class-level doc block for how that freeze is enforced). Unlike
 * `App\Domain\Faq\FaqArticlePublishState` (which has a documented reason for
 * a third `unpublished` state), no cited document here
 * (`requirements.md`, `design.md`) describes a package version ever being
 * withdrawn/retired independently of a newer version simply superseding it
 * — `design.md`: "Later changes create new versions, never mutate accepted
 * quote contents." A superseded version stays `published` (and frozen)
 * forever; only `Actions\ReviseServicePackageVersion` moves a package
 * forward, by creating a new version, never by changing an old one's status.
 * Inventing a third status without a citing requirement would be scope
 * invention, not implementation.
 */
final class ServicePackageVersionStatus
{
    public const string DRAFT = 'draft';

    public const string PUBLISHED = 'published';

    /**
     * @var list<string>
     */
    public const array KNOWN_STATUSES = [
        self::DRAFT,
        self::PUBLISHED,
    ];

    public static function isKnown(string $status): bool
    {
        return in_array($status, self::KNOWN_STATUSES, true);
    }

    /**
     * @throws InvalidArgumentException when `$status` is not one of
     *                                  `self::KNOWN_STATUSES`.
     */
    public static function assertKnown(string $status): void
    {
        if (! self::isKnown($status)) {
            throw new InvalidArgumentException(
                "Unknown service package version status [{$status}]. Known statuses: ".implode(', ', self::KNOWN_STATUSES).'.'
            );
        }
    }
}
