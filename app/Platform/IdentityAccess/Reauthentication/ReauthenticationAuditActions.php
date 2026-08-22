<?php

declare(strict_types=1);

namespace App\Platform\IdentityAccess\Reauthentication;

/**
 * The action names this module writes to `audit_events` via
 * `App\Platform\Audit\Audit::record()`. Named constants so
 * `SensitiveActions`/tests reference the same values
 * `ReauthenticationService` actually emits, matching the established
 * audit-action naming pattern used across this codebase.
 *
 * Neither constant is added to `App\Platform\Audit\SensitiveActions::ACTIONS`
 * — both are routine, machine-driven outcomes of the freshness-window check
 * (the middleware raising a challenge, or a future controller reporting one
 * satisfied), not a human-authored decision with a "reason" to require in
 * the audit-reason sense.
 */
final class ReauthenticationAuditActions
{
    public const string CHALLENGED = 'REAUTHENTICATION_CHALLENGED';

    public const string SATISFIED = 'REAUTHENTICATION_SATISFIED';
}
