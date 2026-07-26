<?php

declare(strict_types=1);

namespace App\Domain\ServiceCatalog\Actions;

use App\Domain\ServiceCatalog\Models\EvidenceRequirement;
use App\Domain\ServiceCatalog\Models\ServicePackage;
use App\Domain\ServiceCatalog\Models\ServicePackageItem;
use App\Domain\ServiceCatalog\Models\ServicePackageVersion;
use App\Domain\ServiceCatalog\Models\SubstitutionPolicy;
use App\Domain\ServiceCatalog\ServiceCatalogAuditActions;
use App\Domain\ServiceCatalog\ServicePackageVersionStatus;
use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditOutcome;
use App\Platform\Audit\AuditSource;
use App\Platform\Audit\AuditSubject;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Creates the NEXT draft version for a package that already has a
 * published one, by copying every item (and each item's evidence
 * requirements / substitution policies) from the current published
 * version into a brand-new `draft` row. The ONLY way this module advances
 * a package past its first version — `design.md`: "Later changes create
 * new versions, never mutate accepted quote contents."
 *
 * The freshly created draft is a starting point for further authoring (a
 * later admin-editor batch would let items be added/changed/removed on it
 * before it, too, is published via `PublishServicePackageVersion`) —
 * copying, not linking, so editing the new draft's items can never affect
 * the still-published previous version's own (now-frozen) rows.
 *
 * Refuses to run if the package already has an open draft (at most one
 * draft per package at a time — an admin finishes or discards the current
 * draft before starting another) or if the package has never been
 * published at all (nothing to revise from; use `DefineServicePackage` for
 * a brand-new package instead).
 *
 * Audited via `Audit::record()` but not `SensitiveActions`-listed — see
 * `App\Domain\ServiceCatalog\ServiceCatalogAuditActions`'s own doc block.
 */
final readonly class ReviseServicePackageVersion
{
    public function __invoke(
        ServicePackage $package,
        int|string $actorReference,
        string $actorRole = 'admin',
        AuditSource $auditSource = AuditSource::Panel,
        ?string $reason = null,
    ): ServicePackageVersion {
        return DB::transaction(function () use ($package, $actorReference, $actorRole, $auditSource, $reason): ServicePackageVersion {
            /** @var ServicePackage $package */
            $package = ServicePackage::query()->lockForUpdate()->findOrFail($package->id);

            if ($package->draftVersion() !== null) {
                throw new InvalidArgumentException(
                    "Service package [{$package->code}] already has an open draft version; publish or discard it before revising again."
                );
            }

            $current = $package->currentPublishedVersion();

            if ($current === null) {
                throw new InvalidArgumentException(
                    "Service package [{$package->code}] has never been published; nothing to revise."
                );
            }

            $newVersion = ServicePackageVersion::create([
                'service_package_id' => $package->id,
                'version_number' => $current->version_number + 1,
                'status' => ServicePackageVersionStatus::DRAFT,
            ]);

            foreach ($current->items()->with(['evidenceRequirements', 'substitutionPolicies'])->get() as $item) {
                self::copyItem($newVersion, $item);
            }

            Audit::record(
                action: ServiceCatalogAuditActions::PACKAGE_VERSION_REVISED,
                subject: new AuditSubject('service_package_version', $newVersion->id, $newVersion->version_number),
                outcome: AuditOutcome::Allowed,
                actorRef: $actorReference,
                actorRole: $actorRole,
                source: $auditSource,
                reason: $reason,
                metadata: ['note' => "Revised from version {$current->version_number}."],
            );

            return $newVersion->refresh();
        });
    }

    private static function copyItem(ServicePackageVersion $newVersion, ServicePackageItem $source): void
    {
        $newItem = ServicePackageItem::create([
            'service_package_version_id' => $newVersion->id,
            'service_definition_id' => $source->service_definition_id,
            'item_type' => $source->item_type,
            'quantity' => $source->quantity,
            'unit' => $source->unit,
            'fulfillment_owner' => $source->fulfillment_owner,
            'service_area' => $source->service_area,
            'requires_schedule_window' => $source->requires_schedule_window,
            'evidence_required' => $source->evidence_required,
            'notes' => $source->notes,
        ]);

        foreach ($source->evidenceRequirements as $evidence) {
            EvidenceRequirement::create([
                'service_package_item_id' => $newItem->id,
                'description' => $evidence->description,
                'is_required' => $evidence->is_required,
            ]);
        }

        foreach ($source->substitutionPolicies as $policy) {
            SubstitutionPolicy::create([
                'service_package_item_id' => $newItem->id,
                'substitute_service_definition_id' => $policy->substitute_service_definition_id,
                'requires_customer_approval' => $policy->requires_customer_approval,
                'reason' => $policy->reason,
            ]);
        }
    }
}
