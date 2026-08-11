<?php

declare(strict_types=1);

namespace App\Platform\IdentityAccess\Roles;

/**
 * The audit action names `Actions\GrantActorRole` / `Actions\RevokeActorRole`
 * write — the `platform-identity-seam` design doc's decision 5. Named
 * constants, not inline string literals, so `SensitiveActions::ACTIONS` and
 * every call site reference the same values — same convention as
 * `App\Platform\IdentityAccess\Mfa\MfaAuditActions` and
 * `App\Platform\Payment\PaymentAuditActions`.
 *
 * Both values are on `SensitiveActions::ACTIONS` (see that file's own
 * updated doc comment) — granting or revoking a role is the same
 * privilege-escalation category as `MFA_RESET`/`CERTIFICATE_REVOKE`, so a
 * mandatory reason is not optional here.
 */
final class RoleAuditActions
{
    /**
     * Written by `Actions\GrantActorRole` with `AuditOutcome::Allowed`,
     * subject = the new `ActorRoleAssignment` row.
     */
    public const string GRANT = 'ROLE_GRANT';

    /**
     * Written by `Actions\RevokeActorRole` with `AuditOutcome::Allowed`.
     * Revocation can affect more than one row (multiple prior grants of the
     * same role to the same actor), so the subject identifies the
     * actor/role pair rather than a single row id — see that Action's own
     * doc block.
     */
    public const string REVOKE = 'ROLE_REVOKE';
}
