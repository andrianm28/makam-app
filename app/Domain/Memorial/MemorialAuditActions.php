<?php

declare(strict_types=1);

namespace App\Domain\Memorial;

/**
 * The audit action names the Memorial module records — the module-level
 * counterpart of `App\Domain\PlotReservation\PlotReservationAuditActions`.
 *
 * Deliberately NOT on `SensitiveActions::ACTIONS`: memorial moderation
 * and publishing are privacy/moderation operations whose audit rows
 * carry no reason requirement — none of the six transitions is a
 * financial, gate, credential, or bulk-export act (the classes
 * `SensitiveActions` exists to gate). Content submission and abuse
 * reporting are not audited at all: the former is a pending-state draft
 * the moderation trail already captures, and the plan's Task 3 brief
 * names no audit constant for either.
 */
final class MemorialAuditActions
{
    public const string MEMORIAL_PROFILE_CREATED = 'MEMORIAL_PROFILE_CREATED';

    public const string MEMORIAL_EDITOR_GRANTED = 'MEMORIAL_EDITOR_GRANTED';

    public const string MEMORIAL_PUBLISHED = 'MEMORIAL_PUBLISHED';

    public const string MEMORIAL_UNPUBLISHED = 'MEMORIAL_UNPUBLISHED';

    public const string MEMORIAL_QR_ROTATED = 'MEMORIAL_QR_ROTATED';

    public const string MEMORIAL_CONTENT_MODERATED = 'MEMORIAL_CONTENT_MODERATED';

    /**
     * Added with Task 4's family surface — the privacy-mode transition
     * (`ChangeMemorialPrivacy`) records the previous/new modes in the
     * audit metadata. Same deliberation as the constants above: not a
     * financial, gate, credential, or bulk-export act, so no
     * `SensitiveActions` reason requirement.
     */
    public const string MEMORIAL_PRIVACY_CHANGED = 'MEMORIAL_PRIVACY_CHANGED';

    /**
     * Added with Task 4's moderation queue (`ResolveModerationCase`) — a
     * case closed as resolved/dismissed must carry its reason in the audit
     * row; the action enforces the reason requirement itself.
     */
    public const string MEMORIAL_MODERATION_CASE_RESOLVED = 'MEMORIAL_MODERATION_CASE_RESOLVED';

    public const string MEMORIAL_MODERATION_CASE_DISMISSED = 'MEMORIAL_MODERATION_CASE_DISMISSED';
}
