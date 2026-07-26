<?php

declare(strict_types=1);

namespace App\Domain\ServiceCatalog\Actions;

use App\Domain\ServiceCatalog\Models\EvidenceRequirement;
use App\Domain\ServiceCatalog\Models\ServiceDefinition;
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
 * Creates a new `service_packages` row together with its version-1 DRAFT
 * and that draft's initial items (and, per item, any evidence requirements
 * / substitution policies) — the ONLY way this module creates a package.
 * Never `ServicePackage::create()`/`ServicePackageVersion::create()`
 * directly from outside this class (`app/Domain/README.md`: "Domain logic
 * lives here ... not in controllers, Livewire components, or Filament
 * Resources").
 *
 * ---------------------------------------------------------------------------
 * Items are supplied here, not added one-by-one afterward — a scope
 * boundary of this batch, not a permanent constraint
 * ---------------------------------------------------------------------------
 * This batch (S4-T1, master-data/seeds) ships no "add item to an existing
 * draft" Action — `requirements.md` AC1's item list is required to define a
 * package version at all, so this Action requires at least one item up
 * front rather than tolerating an empty, incomplete draft in between calls.
 * A later batch's admin editor (`tasks.md`: "Build admin editor with
 * publish workflow" — a separate, unchecked task line, explicitly out of
 * this batch's scope, which also excludes any `app/Filament/**` change) is
 * where item-by-item incremental editing of an open draft belongs; nothing
 * here prevents adding such an Action later, since
 * `Models\ServicePackageItem`'s own immutability guard only blocks writes
 * once the OWNING VERSION is published, not while it is still `draft`.
 *
 * Audited via `Audit::record()` but not `SensitiveActions`-listed — see
 * `App\Domain\ServiceCatalog\ServiceCatalogAuditActions`'s own doc block.
 */
final readonly class DefineServicePackage
{
    /**
     * @param  list<array{
     *     service_definition_id: int,
     *     item_type: string,
     *     quantity: int,
     *     unit: string,
     *     fulfillment_owner: string,
     *     service_area?: string|null,
     *     requires_schedule_window?: bool,
     *     evidence_required?: bool,
     *     notes?: string|null,
     *     evidence_requirements?: list<array{description: string, is_required?: bool}>,
     *     substitution_policies?: list<array{substitute_service_definition_id: int, requires_customer_approval?: bool, reason?: string|null}>,
     * }>  $items  AC1's full per-item attribute list. At least one item is
     *             required — see class-level doc block.
     */
    public function __invoke(
        string $code,
        string $name,
        array $items,
        int|string $actorReference,
        ?string $description = null,
        string $actorRole = 'admin',
        AuditSource $auditSource = AuditSource::Panel,
        ?string $reason = null,
    ): ServicePackage {
        self::assertNonBlank($code, 'code');
        self::assertNonBlank($name, 'name');

        if ($items === []) {
            throw new InvalidArgumentException('A service package must be defined with at least one item.');
        }

        return DB::transaction(function () use ($code, $name, $description, $items, $actorReference, $actorRole, $auditSource, $reason): ServicePackage {
            $package = ServicePackage::create([
                'code' => trim($code),
                'name' => trim($name),
                'description' => $description,
                'is_active' => true,
            ]);

            $version = ServicePackageVersion::create([
                'service_package_id' => $package->id,
                'version_number' => 1,
                'status' => ServicePackageVersionStatus::DRAFT,
            ]);

            foreach ($items as $itemSpec) {
                self::createItem($version, $itemSpec);
            }

            Audit::record(
                action: ServiceCatalogAuditActions::PACKAGE_DEFINED,
                subject: new AuditSubject('service_package', $package->id, $version->version_number),
                outcome: AuditOutcome::Allowed,
                actorRef: $actorReference,
                actorRole: $actorRole,
                source: $auditSource,
                reason: $reason,
                metadata: ['new_state' => ServicePackageVersionStatus::DRAFT],
            );

            return $package->refresh();
        });
    }

    /**
     * @param  array{
     *     service_definition_id: int,
     *     item_type: string,
     *     quantity: int,
     *     unit: string,
     *     fulfillment_owner: string,
     *     service_area?: string|null,
     *     requires_schedule_window?: bool,
     *     evidence_required?: bool,
     *     notes?: string|null,
     *     evidence_requirements?: list<array{description: string, is_required?: bool}>,
     *     substitution_policies?: list<array{substitute_service_definition_id: int, requires_customer_approval?: bool, reason?: string|null}>,
     * }  $spec
     */
    private static function createItem(ServicePackageVersion $version, array $spec): void
    {
        $quantity = $spec['quantity'] ?? 0;
        $unit = (string) ($spec['unit'] ?? '');

        // >= 0, not >= 1: an EXCLUDED item legitimately carries quantity 0
        // (nothing of it is included) — only a negative or non-integer
        // value is a caller error. INCLUDED/OPTIONAL items with a
        // meaningless zero quantity are a content-quality concern for a
        // later admin-editor batch's validation UI, not a hard invariant
        // this Action enforces.
        if (! is_int($quantity) || $quantity < 0) {
            throw new InvalidArgumentException('Each service package item requires a non-negative integer quantity.');
        }

        if (trim($unit) === '') {
            throw new InvalidArgumentException('Each service package item requires a non-blank unit.');
        }

        // findOrFail: a service_definition_id that does not exist is a
        // caller error, not a state this module tolerates silently — same
        // reasoning as `App\Domain\Faq\Actions\CreateFaqArticleDraft`'s own
        // `FaqCategory::query()->findOrFail(...)` check.
        ServiceDefinition::query()->findOrFail($spec['service_definition_id']);

        $item = ServicePackageItem::create([
            'service_package_version_id' => $version->id,
            'service_definition_id' => $spec['service_definition_id'],
            'item_type' => $spec['item_type'],
            'quantity' => $quantity,
            'unit' => trim($unit),
            'fulfillment_owner' => $spec['fulfillment_owner'],
            'service_area' => $spec['service_area'] ?? null,
            'requires_schedule_window' => $spec['requires_schedule_window'] ?? false,
            'evidence_required' => $spec['evidence_required'] ?? false,
            'notes' => $spec['notes'] ?? null,
        ]);

        foreach ($spec['evidence_requirements'] ?? [] as $evidenceSpec) {
            EvidenceRequirement::create([
                'service_package_item_id' => $item->id,
                'description' => $evidenceSpec['description'],
                'is_required' => $evidenceSpec['is_required'] ?? true,
            ]);
        }

        foreach ($spec['substitution_policies'] ?? [] as $substitutionSpec) {
            SubstitutionPolicy::create([
                'service_package_item_id' => $item->id,
                'substitute_service_definition_id' => $substitutionSpec['substitute_service_definition_id'],
                'requires_customer_approval' => $substitutionSpec['requires_customer_approval'] ?? true,
                'reason' => $substitutionSpec['reason'] ?? null,
            ]);
        }
    }

    private static function assertNonBlank(string $value, string $field): void
    {
        if (trim($value) === '') {
            throw new InvalidArgumentException("Service package [{$field}] must not be blank.");
        }
    }
}
