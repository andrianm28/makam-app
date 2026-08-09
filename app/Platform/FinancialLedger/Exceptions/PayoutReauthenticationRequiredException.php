<?php

declare(strict_types=1);

namespace App\Platform\FinancialLedger\Exceptions;

use RuntimeException;

/**
 * The approver has not re-proved their identity recently enough to approve a
 * payout.
 *
 * `AGENTS.md` §Authentication: "Require recent re-authentication for financial,
 * gate, bank-detail, certificate, plot-override, and bulk-export actions."
 * `docs/security/authentication-and-mfa.md` §5 names "payment/refund/payout
 * approval" first on its list.
 *
 * Raised only after
 * `App\Platform\IdentityAccess\Reauthentication\ReauthenticationService
 * ::challenge()` has recorded the refusal, so a stale approval attempt always
 * leaves an audit trail rather than only an exception.
 */
final class PayoutReauthenticationRequiredException extends RuntimeException
{
    public static function forApprover(string $approverRef, int $freshnessSeconds): self
    {
        return new self(
            "Approver [{$approverRef}] has no satisfied re-authentication within the last ".
            "{$freshnessSeconds} seconds. A payout approval requires recent re-authentication ".
            'before it may proceed.'
        );
    }
}
