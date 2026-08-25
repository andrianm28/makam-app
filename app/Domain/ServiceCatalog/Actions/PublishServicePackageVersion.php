<?php

declare(strict_types=1);

namespace App\Domain\ServiceCatalog\Actions;

use App\Domain\ServiceCatalog\Models\ServicePackageVersion;
use App\Domain\ServiceCatalog\ServiceCatalogAuditActions;
use App\Domain\ServiceCatalog\ServicePackageVersionStatus;
use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditOutcome;
use App\Platform\Audit\AuditSource;
use App\Platform\Audit\AuditSubject;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Publishes a draft package version — the ONLY path that moves
 * `service_package_versions.status` from `draft` to `published`. After this
 * call succeeds, `Models\ServicePackageVersion`'s own immutability guard
 * (see that model's class-level doc block) permanently freezes the row and
 * every one of its items — requirements.md AC2.
 *
 * ---------------------------------------------------------------------------
 * Idempotent, not "publish again" — design.md's duplicate/retry-safe note
 * ---------------------------------------------------------------------------
 * `design.md`'s "duplicate/retry-safe" state note: "republishing the same
 * version must be idempotent, never create a second version." Calling this
 * on an already-`published` version is a no-op that returns the version
 * unchanged — it does NOT throw, and it does NOT write a second audit
 * event. This matters for a caller retrying after an ambiguous network
 * response (did the first publish commit or not?) — the safe behaviour is
 * "confirm it is published," not "fail because it already is."
 *
 * Requires at least one item — AC1 defines a package version BY its items;
 * publishing an empty draft would freeze a version with nothing in it,
 * which is never a state a real quote-expansion caller could use.
 *
 * Audited via `Audit::record()` but not `SensitiveActions`-listed — see
 * `App\Domain\ServiceCatalog\ServiceCatalogAuditActions`'s own doc block.
 */
final readonly class PublishServicePackageVersion
{
    public function __invoke(
        ServicePackageVersion $version,
        int|string $actorReference,
        string $actorRole = 'admin',
        AuditSource $auditSource = AuditSource::Panel,
        ?string $reason = null,
    ): ServicePackageVersion {
        return DB::transaction(function () use ($version, $actorReference, $actorRole, $auditSource, $reason): ServicePackageVersion {
            /** @var ServicePackageVersion $version */
            $version = ServicePackageVersion::query()->lockForUpdate()->findOrFail($version->id);

            if ($version->isPublished()) {
                return $version;
            }

            if ($version->items()->count() === 0) {
                throw new InvalidArgumentException(
                    "service_package_versions row [{$version->id}] has zero items and cannot be published."
                );
            }

            $version->forceFill([
                'status' => ServicePackageVersionStatus::PUBLISHED,
                'published_at' => CarbonImmutable::now(),
            ])->save();

            Audit::record(
                action: ServiceCatalogAuditActions::PACKAGE_VERSION_PUBLISHED,
                subject: new AuditSubject('service_package_version', $version->id, $version->version_number),
                outcome: AuditOutcome::Allowed,
                actorRef: $actorReference,
                actorRole: $actorRole,
                source: $auditSource,
                reason: $reason,
                metadata: [
                    'previous_state' => ServicePackageVersionStatus::DRAFT,
                    'new_state' => ServicePackageVersionStatus::PUBLISHED,
                ],
            );

            return $version->refresh();
        });
    }
}
