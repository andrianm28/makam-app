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
 * None of the three constants is added to
 * `App\Platform\Audit\SensitiveActions::ACTIONS` — all three are routine,
 * machine-driven outcomes of the freshness-window check (the middleware
 * raising a challenge, a future controller reporting one satisfied, or
 * `App\Filament\Admin\Pages\PasswordReauthentication::submit()` recording a
 * wrong-password attempt), not a human-authored decision with a "reason" to
 * require in the audit-reason sense.
 */
final class ReauthenticationAuditActions
{
    public const string CHALLENGED = 'REAUTHENTICATION_CHALLENGED';

    public const string SATISFIED = 'REAUTHENTICATION_SATISFIED';

    public const string FAILED = 'REAUTHENTICATION_FAILED';
}
