<?php

declare(strict_types=1);

namespace App\Domain\PlotInventory;

/**
 * The action names this module writes to `audit_events` via
 * `App\Platform\Audit\Audit::record()` — written by
 * `App\Domain\PlotInventory\Actions\CreateCemeteryBlock`. Named constants
 * (not inline string literals) so tests and any future caller reference
 * the same values the action actually emits — mirrors
 * `App\Domain\CemeteryDirectory\CemeteryAuditActions`'s shape and
 * reasoning.
 *
 * ---------------------------------------------------------------------------
 * Deliberately NOT added to `App\Platform\Audit\SensitiveActions::ACTIONS`
 * ---------------------------------------------------------------------------
 * Creating a block and bulk-generating its plots is a content-master-data
 * action — the same judgement `CemeteryPackageAuditActions` documents for
 * package create/edit: mistakes are embarrassing or confusing, not fraud-
 * or harm-shaped, and there is no human-authored "reason" a mandatory-
 * reason gate would meaningfully extract. The `Audit::record()` calls in
 * the action still run (complete "who changed what, when" history), but
 * neither name appears on `SensitiveActions::ACTIONS`, so a blank reason
 * never throws `AuditReasonRequiredException` for them. Extend
 * `SensitiveActions` deliberately if a future batch reclassifies plot
 * inventory writes as sensitive.
 *
 * The metadata-allowlist note (`App\Platform\Audit\MetadataAllowlist`):
 * `capacity` and `plot_count` are deliberately NOT on the allowlist, so
 * the action carries those numbers in the audit `reason` text instead of
 * metadata — the allowlist is not extended for this module.
 */
final class PlotInventoryAuditActions
{
    public const string CEMETERY_BLOCK_CREATED = 'CEMETERY_BLOCK_CREATED';

    public const string GRAVE_PLOTS_GENERATED = 'GRAVE_PLOTS_GENERATED';
}
