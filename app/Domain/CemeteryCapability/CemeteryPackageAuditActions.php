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

    /**
     * A grave package's firm, bookable price changed.
     *
     * Distinct from `UPDATED` on purpose. `UPDATED` covers catalogue edits —
     * name, class label, availability, sort order. This one covers money, and
     * it is listed in `Platform\Audit\SensitiveActions::ACTIONS` so a change
     * cannot be recorded without a reason an operator can later read back.
     *
     * Distinct from the service-fee equivalent
     * (`SERVICE_DEFINITION_PRICE_VERSION_RECORDED`) for the same reason: an
     * operator reviewing the trail must be able to tell a change to a burial
     * plot's price from a change to an ambulance fee without joining tables.
     */
    public const string PRICE_VERSION_RECORDED = 'CEMETERY_PACKAGE_PRICE_VERSION_RECORDED';
}
