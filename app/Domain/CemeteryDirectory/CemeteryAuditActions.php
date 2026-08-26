<?php

declare(strict_types=1);

namespace App\Domain\CemeteryDirectory;

/**
 * The action names this module writes to `audit_events` via
 * `App\Platform\Audit\Audit::record()` for admin-managed cemetery writes
 * (the `CemeteryResource` Filament resource). Named constants (not inline
 * string literals) so tests and any future caller reference the same values
 * the resource actually emits — mirrors `App\Domain\Faq\FaqAuditActions`'s
 * shape and reasoning.
 *
 * ---------------------------------------------------------------------------
 * Deliberately NOT added to `App\Platform\Audit\SensitiveActions::ACTIONS`
 * ---------------------------------------------------------------------------
 * Creating, editing, or deleting a cemetery master-data row is a
 * content-editorial action — the same judgement `FaqAuditActions` documents
 * for FAQ article create/edit/publish/unpublish/reorder: mistakes are
 * embarrassing or confusing, not fraud- or harm-shaped, and there is no
 * human-authored "reason" a mandatory-reason gate would meaningfully
 * extract. The `Audit::record()` calls in the resource still run (complete
 * "who changed what, when" history), but none of these three names appear
 * on `SensitiveActions::ACTIONS`, so a blank reason never throws
 * `AuditReasonRequiredException` for them. Extend `SensitiveActions`
 * deliberately if a future batch reclassifies master-data edits as
 * sensitive.
 */
final class CemeteryAuditActions
{
    public const string CREATED = 'CEMETERY_CREATED';

    public const string UPDATED = 'CEMETERY_UPDATED';

    public const string DELETED = 'CEMETERY_DELETED';

    public const string PLOT_TRACKING_MODE_CHANGED = 'CEMETERY_PLOT_TRACKING_MODE_CHANGED';
}
