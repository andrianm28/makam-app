<?php

declare(strict_types=1);

namespace App\Domain\CemeteryCapability;

/**
 * The action names this module writes to `audit_events` via
 * `App\Platform\Audit\Audit::record()` for admin-managed `cemetery_packages`
 * writes (the `PackagesRelationManager` on `CemeteryResource`). Named
 * constants (not inline string literals) so tests and any future caller
 * reference the same values the relation manager actually emits — mirrors
 * `App\Domain\CemeteryDirectory\CemeteryAuditActions`'s shape and
 * reasoning.
 *
 * ---------------------------------------------------------------------------
 * Deliberately NOT added to `App\Platform\Audit\SensitiveActions::ACTIONS`
 * ---------------------------------------------------------------------------
 * Creating or editing a package/class availability row is a
 * content-editorial action — the same judgement `CemeteryAuditActions`
 * documents for cemetery create/edit/delete: mistakes are embarrassing or
 * confusing, not fraud- or harm-shaped, and there is no human-authored
 * "reason" a mandatory-reason gate would meaningfully extract. The
 * `Audit::record()` calls in the relation manager still run (complete "who
 * changed what, when" history), but neither name appears on
 * `SensitiveActions::ACTIONS`, so a blank reason never throws
 * `AuditReasonRequiredException` for them. Extend `SensitiveActions`
 * deliberately if a future batch reclassifies package edits as sensitive.
 *
 * There is deliberately no DELETED constant: the admin-master-data plan
 * bounded this relation manager to list + inline create/edit only, so no
 * admin delete path exists (see `PackagesRelationManager`'s doc block).
 */
final class CemeteryPackageAuditActions
{
    public const string CREATED = 'CEMETERY_PACKAGE_CREATED';

    public const string UPDATED = 'CEMETERY_PACKAGE_UPDATED';
}
