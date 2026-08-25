<?php

declare(strict_types=1);

namespace App\Domain\ServiceCatalog\Exceptions;

use RuntimeException;

/**
 * `package-and-service-bundles/requirements.md` AC2: "THE SYSTEM SHALL NOT
 * allow modification of a published package version." Thrown from five call
 * sites — see each model's own class-level doc block:
 *
 * - `Models\ServicePackageVersion::booted()` `saving`/`deleting` — any
 *   save or delete on a version whose ORIGINAL status was already
 *   `published` (`forVersion()`).
 * - `Models\ServicePackageVersion::booted()` `saving` on INSERT — a row
 *   created directly with `status = published`, which would otherwise
 *   bypass `Actions\PublishServicePackageVersion`'s zero-item refusal
 *   (`forDirectPublishedInsert()`).
 * - `Models\ServicePackageItem::booted()` — any insert/update/delete of an
 *   item belonging to an already-published version, on EITHER the incoming
 *   or the original owning version id (`forItemOfVersion()`).
 * - `Models\ServicePackage::booted()` `deleting` — deleting a package that
 *   still owns at least one published version, which the FK's
 *   `cascadeOnDelete()` would otherwise destroy without firing a single
 *   Eloquent event (`forPackage()`).
 * - `Models\SubstitutionPolicy::booted()` and
 *   `Models\EvidenceRequirement::booted()` — any insert/update/delete of a
 *   per-item child row whose owning item belongs to a published version
 *   (`forChildOfItem()`).
 *
 * Mirrors `App\Platform\Audit\Exceptions\AuditRecordIsImmutableException`'s
 * own shape: a plain `RuntimeException` with a named static factory per call
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
            'service_package_items cannot be added, changed, or removed on service_package_versions '.
            "row [{$versionId}] because that version is published and therefore immutable; ".
            'create a new version instead (Actions\ReviseServicePackageVersion).'
        );
    }

    /**
     * A `service_package_versions` row created directly with
     * `status = published` never passes through
     * `Actions\PublishServicePackageVersion`, so it never meets that
     * Action's "a version with zero items cannot be published" refusal —
     * which is how a frozen, empty version becomes reachable in one line.
     */
    public static function forDirectPublishedInsert(): self
    {
        return new self(
            'a service_package_versions row cannot be INSERTED with status [published]; '.
            'create it as [draft] and publish it via Actions\PublishServicePackageVersion, '.
            'which is the only path that may freeze a version and the only one that refuses '.
            'to freeze an empty one.'
        );
    }

    public static function forPackage(int|string $packageId): self
    {
        return new self(
            "service_packages row [{$packageId}] cannot be deleted because it still owns at least one ".
            'published version; the database cascade would destroy that frozen version, its items, its '.
            'substitution policies and its evidence requirements without firing a single Eloquent event. '.
            'Deactivate the package (is_active = false) instead.'
        );
    }

    public static function forChildOfItem(int|string|null $itemId, string $childTable): self
    {
        return new self(
            "{$childTable} rows cannot be added, changed, or removed on service_package_items row ".
            "[{$itemId}] because that item belongs to a published (and therefore immutable) package ".
            'version; create a new version instead (Actions\ReviseServicePackageVersion).'
        );
    }
}
