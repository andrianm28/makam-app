<?php

declare(strict_types=1);

namespace App\Domain\PlotInventory;

/**
 * The action names this module writes to `audit_events` via
 * `App\Platform\Audit\Audit::record()` — written by
 * `App\Domain\PlotInventory\Actions\CreateCemeteryBlock` and by the admin
 * plot state-override actions (`BlocksRelationManager` / `GravePlotsResource`
 * row actions, Task 2 of the P3 plot-inventory plan). Named constants
 * (not inline string literals) so tests and any future caller reference
 * the same values the actions actually emit — mirrors
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

    /**
     * Written by the admin panel's plot state-override row actions
     * ('Tandai Terisi'/'Tandai Perawatan'/'Tandai Tersedia') whenever an
     * operator flips `grave_plots.plot_state` — the same "who changed what,
     * when" record as the inventory creation actions, so the audit trail is
     * complete for every state a plot passes through.
     */
    public const string GRAVE_PLOT_STATE_CHANGED = 'GRAVE_PLOT_STATE_CHANGED';
}
