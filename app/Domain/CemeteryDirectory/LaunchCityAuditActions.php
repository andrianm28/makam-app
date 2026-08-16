<?php

declare(strict_types=1);

namespace App\Domain\CemeteryDirectory;

/**
 * The action names this module writes to `audit_events` via
 * `App\Platform\Audit\Audit::record()` for admin-managed launch-city writes
 * (the `LaunchCityResource` Filament resource). Named constants (not inline
 * string literals) so tests and any future caller reference the same values
 * the resource actually emits — mirrors `CemeteryAuditActions`'s shape and
 * reasoning.
 *
 * `REORDERED` is the one action here with no `CemeteryAuditActions`
 * counterpart: the launch-city catalogue is a flat list (no per-group
 * ordering), so the reorder is a sort_order swap of two adjacent rows
 * performed by the resource's table actions and audited with the moving
 * row's previous/new sort order in `metadata` (`previous_state` /
 * `new_state` — the two `MetadataAllowlist` keys that already carry
 * "what changed" for every other state transition in this codebase).
 *
 * ---------------------------------------------------------------------------
 * Deliberately NOT added to `App\Platform\Audit\SensitiveActions::ACTIONS`
 * ---------------------------------------------------------------------------
 * Same judgement as `CemeteryAuditActions` and `FaqAuditActions`: creating,
 * editing, deleting, or reordering a launch-city master-data row is a
 * content-editorial action — mistakes are embarrassing or confusing, not
 * fraud- or harm-shaped, and there is no human-authored "reason" a
 * mandatory-reason gate would meaningfully extract. The `Audit::record()`
 * calls in the resource still run (complete "who changed what, when"
 * history), but none of these four names appear on
 * `SensitiveActions::ACTIONS`, so a blank reason never throws
 * `AuditReasonRequiredException` for them. Extend `SensitiveActions`
 * deliberately if a future batch reclassifies master-data edits as
 * sensitive.
 */
final class LaunchCityAuditActions
{
    public const string CREATED = 'LAUNCH_CITY_CREATED';

    public const string UPDATED = 'LAUNCH_CITY_UPDATED';

    public const string DELETED = 'LAUNCH_CITY_DELETED';

    public const string REORDERED = 'LAUNCH_CITY_REORDERED';
}
