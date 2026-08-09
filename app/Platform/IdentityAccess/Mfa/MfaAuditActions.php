<?php

declare(strict_types=1);

namespace App\Platform\IdentityAccess\Mfa;

/**
 * The action names this module writes to `audit_events` via
 * `App\Platform\Audit\Audit::record()` — requirements.md AC6: "make MFA
 * enrolment, challenge, recovery, and reset auditable and rate-limited."
 * Named constants (not inline string literals scattered across the three
 * service classes) so `SensitiveActions`/tests reference the same values
 * the services actually emit.
 *
 * Only `RESET` is added to `App\Platform\Audit\SensitiveActions::ACTIONS`
 * (mandatory reason) — see that file's own updated doc comment for why the
 * other four are deliberately NOT sensitive-listed.
 */
final class MfaAuditActions
{
    public const string ENROLMENT_CONFIRMED = 'MFA_ENROLMENT_CONFIRMED';

    public const string CHALLENGE_SUCCEEDED = 'MFA_CHALLENGE_SUCCEEDED';

    public const string CHALLENGE_FAILED = 'MFA_CHALLENGE_FAILED';

    public const string RECOVERY_USED = 'MFA_RECOVERY_USED';

    public const string RECOVERY_CODES_REGENERATED = 'MFA_RECOVERY_CODES_REGENERATED';

    public const string RESET = 'MFA_RESET';
}
