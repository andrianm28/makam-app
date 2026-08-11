<?php

declare(strict_types=1);

namespace App\Platform\FinancialLedger\Exceptions;

use RuntimeException;

/**
 * The caller may not recognise a vendor liability for this vendor.
 *
 * Its OWN type rather than a reuse of `PayoutNotAuthorisedException`, for the
 * same reason `ReconciliationNotAuthorisedException` is its own: recognising a
 * debt and paying one are different permissions, and sharing the refusal type
 * is the first step towards sharing the check. Assessing creates a liability
 * that a payout later discharges — an actor who may do the first must not
 * inherit the second, and the type system should not blur them.
 *
 * Fail-closed: no grant row means no authorisation, never "unrestricted until
 * someone configures it", and an empty role list is not permission.
 *
 * Names the vendor, never the amount, the order, or any identity detail beyond
 * the actor reference itself.
 */
final class VendorPayableNotAuthorisedException extends RuntimeException
{
    public static function forActorContext(string $vendorId): self
    {
        return new self(
            'The authenticated actor has no explicit finance authority to recognise a '
            ."vendor liability for vendor [{$vendorId}]."
        );
    }

    /**
     * The unattended/system path was requested while a human actor is present
     * on the context.
     *
     * This is a REFUSAL, not a fallback. Letting an authenticated request take
     * the system path would write an audit row claiming the system acted when a
     * person did — the exact defect this seam was built to close.
     */
    public static function forHumanActorOnUnattendedPath(string $vendorId): self
    {
        return new self(
            'The unattended assessment path was requested for vendor '
            ."[{$vendorId}] while an authenticated actor is present. A human-triggered "
            .'assessment must be authorised as that human, so the audit trail records who acted.'
        );
    }
}
