<?php

declare(strict_types=1);

namespace App\Platform\FinancialLedger\Exceptions;

use RuntimeException;

/**
 * The approver does not hold payout authorisation for this vendor.
 *
 * `docs/security/rbac-matrix.md` puts "Payout/refund" at `No` for every role
 * except a restricted admin and a dedicated finance role. The check that
 * raises this is deliberately fail-closed: no grant row means no authorisation,
 * never "unrestricted until someone configures it" — see
 * `App\Platform\FinancialLedger\Actions\ManualPayout` for the mechanism and
 * why it reads `scope_assignments` rather than `ActorContext::hasRole()`.
 *
 * Names the actor reference and the vendor, never the amount, the proof, or
 * any identity detail beyond the reference itself.
 */
final class PayoutNotAuthorisedException extends RuntimeException
{
    public static function forApprover(string $approverRef, string $vendorId): self
    {
        return new self(
            "Approver [{$approverRef}] does not hold payout authorisation for vendor ".
            "[{$vendorId}]. A payout requires an active, non-revoked privileged scope ".
            'assignment on that vendor.'
        );
    }
}
