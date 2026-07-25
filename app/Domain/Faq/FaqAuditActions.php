<?php

declare(strict_types=1);

namespace App\Domain\Faq;

/**
 * The action names this module writes to `audit_events` via
 * `App\Platform\Audit\Audit::record()`. Named constants (not inline string
 * literals scattered across the four Action classes) so tests and any future
 * caller reference the same values the Actions actually emit — mirrors
 * `App\Platform\IdentityAccess\Mfa\MfaAuditActions`'s own doc block and
 * naming shape.
 *
 * ---------------------------------------------------------------------------
 * Audited, but NONE of these are added to `App\Platform\Audit\
 * SensitiveActions::ACTIONS` — the judgement call this batch's brief asked
 * for, made explicitly here
 * ---------------------------------------------------------------------------
 * `admin-operations` requirements.md AC8 requires "dedicated authorization
 * and audit for sensitive actions." `SensitiveActions`'s own doc block
 * defines that word narrowly: actions with real fraud/harm risk if done
 * without a recorded reason (DITOLAK, plot override, tariff-source change,
 * gate change, manual payment verification, certificate revoke, vendor
 * payout, MFA reset). Creating, editing, publishing, unpublishing, or
 * reordering an FAQ article is a content-editorial action — mistakes are
 * embarrassing or confusing, not fraud- or harm-shaped, and there is no
 * human-authored "reason" a mandatory-reason gate would meaningfully extract
 * (an admin publishing a finished article has nothing more to justify than
 * "it's ready," the same dead-end `MfaAuditActions`'s own doc block already
 * identified for routine MFA outcomes).
 *
 * This is the SAME reasoning `MfaAuditActions`/`SensitiveActions` already
 * apply to `MFA_ENROLMENT_CONFIRMED`/`MFA_CHALLENGE_SUCCEEDED`/
 * `MFA_CHALLENGE_FAILED`/`MFA_RECOVERY_USED` (routine, machine-driven
 * outcomes: audited for a complete history, but not sensitive-listed),
 * applied here to FAQ content management. Every one of the four Actions
 * still calls `Audit::record()` (not skipped) so a complete "who changed
 * what, when" history exists for FAQ content the same way it does for every
 * other admin-facing mutation in this codebase — `Audit::record()`'s own
 * reason requirement simply never triggers for these four action names,
 * because none of them appear on `SensitiveActions::ACTIONS`.
 *
 * (This batch does not — and, per its own brief, must not — edit
 * `app/Platform/Audit/SensitiveActions.php` itself; app/Platform/** is a
 * read-only dependency for this batch. Even absent that constraint, the
 * analysis above concludes these four do not belong on that list.)
 */
final class FaqAuditActions
{
    public const string CREATED = 'FAQ_ARTICLE_CREATED';

    public const string UPDATED = 'FAQ_ARTICLE_UPDATED';

    public const string PUBLISHED = 'FAQ_ARTICLE_PUBLISHED';

    public const string UNPUBLISHED = 'FAQ_ARTICLE_UNPUBLISHED';

    public const string REORDERED = 'FAQ_ARTICLES_REORDERED';
}
