<?php

declare(strict_types=1);

namespace App\Domain\ServiceCatalog\Exceptions;

use RuntimeException;

/**
 * `package-and-service-bundles/requirements.md` AC2: "THE SYSTEM SHALL NOT
 * allow modification of a published package version." Thrown by
 * `Models\ServicePackageVersion::booted()` (any save/delete on a version
 * whose ORIGINAL status was already `published`) and
 * `Models\ServicePackageItem::booted()` (any insert/update/delete of an
 * item belonging to an already-published version) — see both models' own
 * class-level doc blocks. Mirrors
 * `App\Platform\Audit\Exceptions\AuditRecordIsImmutableException`'s own
 * shape: a plain `RuntimeException` with a named static factory per call
 * site, not a generic `InvalidArgumentException`.
 */
final class PublishedServicePackageVersionIsImmutableException extends RuntimeException
{
    public static function forVersion(int|string $versionId): self
    {
        return new self(
            "service_package_versions row [{$versionId}] is published and therefore immutable; ".
            'create a new version instead (Actions\ReviseServicePackageVersion).'
        );
    }

    public static function forItemOfVersion(int|string $versionId): self
    {
        return new self(
            "service_package_items cannot be added, changed, or removed on service_package_versions ".
            "row [{$versionId}] because that version is published and therefore immutable; ".
            'create a new version instead (Actions\ReviseServicePackageVersion).'
        );
    }
}
