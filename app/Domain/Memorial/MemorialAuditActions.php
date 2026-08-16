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
}
