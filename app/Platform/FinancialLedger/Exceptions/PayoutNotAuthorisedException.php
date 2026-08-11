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
    public static function forActorContext(string $vendorId): self
    {
        return new self(
            'The authenticated actor has no explicit finance or restricted-admin payout authority for vendor '
            ."[{$vendorId}]."
        );
    }

    /**
     * The identical, deliberately opaque refusal for EVERY way a caller can
     * fail to reach a payable: an id that does not exist, an id outside their
     * scope, a caller with no identity, a caller with no role, and a caller
     * whose grant was revoked.
     *
     * Minor M6: `pay()` used to look the payable up FIRST and raise
     * `InvalidPayoutException::forUnknownPayable()` for a miss, then authorise
     * and raise this type for a real-but-unauthorised id. The two different
     * refusals made the action an existence oracle over payable UUIDs — feed it
     * ids and the error tells you which ones are real. Low impact on its own
     * (UUIDs are unguessable and no caller reaches this today), but the guard
     * order is the wrong way round for financial code.
     *
     * Copied from the working precedent in-tree:
     * `ReconciliationNotAuthorisedException::forUnavailableException()`, which
     * `Actions\ResolveException` already uses for exactly this reason.
     *
     * The deliberate cost: an authorised finance approver who mistypes a
     * payable id now sees an authorisation-shaped refusal rather than "no such
     * payable". That is the trade the precedent already made — the ledger does
     * not confirm what exists to someone who may not see it.
     */
    public static function forUnavailablePayable(): self
    {
        return new self(
            'The vendor payable is unavailable for this actor.'
        );
    }

    public static function forApprover(string $approverRef, string $vendorId): self
    {
        return new self(
            "Approver [{$approverRef}] does not hold payout authorisation for vendor ".
            "[{$vendorId}]. A payout requires an active, non-revoked privileged scope ".
            'assignment on that vendor.'
        );
    }
}
