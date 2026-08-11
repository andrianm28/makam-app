<?php

declare(strict_types=1);

namespace App\Platform\FinancialLedger\Exceptions;

use InvalidArgumentException;

/**
 * A payout was refused on its own shape — before any row was written, any
 * payable state changed, or any journal batch posted.
 *
 * Separate from `PayoutNotAuthorisedException` and
 * `PayoutReauthenticationRequiredException`, which are refusals about the
 * ACTOR rather than the request. Keeping the three apart means a caller (and a
 * test) can tell "this payout is malformed" from "you may not do this" from
 * "prove who you are again", which are three different things to show an
 * operator and three different things to alert on.
 *
 * Messages carry identifiers, states and minor-unit amounts only — never a
 * proof reference's contents, a bank detail or a customer identity
 * (`AGENTS.md` §Observability).
 */
final class InvalidPayoutException extends InvalidArgumentException
{
    public static function forMissingProof(string $field): self
    {
        return new self(
            "A payout requires a non-blank proof {$field}. AC9 records amount, proof, ".
            'approver and reference; a payout with no proof reference is an assertion '.
            'that money left with nothing behind it.'
        );
    }

    public static function forPayableNotPayable(string $payableId, string $state): self
    {
        return new self(
            "Vendor payable [{$payableId}] is in state [{$state}] and cannot be paid out. ".
            'Only a payable in state [payable] may be paid: a held payable is a debt we '.
            'have not yet recognised, and a paid one is already discharged.'
        );
    }

    public static function forAmountMismatch(
        string $payableId,
        int $payableAmountMinor,
        int $payoutAmountMinor,
    ): self {
        return new self(
            "Payout of [{$payoutAmountMinor}] minor units does not match vendor payable ".
            "[{$payableId}] at [{$payableAmountMinor}]. A payout discharges a payable in ".
            'full; a partial payout would leave the payable marked paid with a residual '.
            'debt that nothing tracks.'
        );
    }

    public static function forNonPositiveAmount(int $amountMinor): self
    {
        return new self(
            "Payout amount [{$amountMinor}] is not a positive number of minor units. ".
            'A payout records money that left the business, so zero and negative amounts '.
            'describe something other than a payout.'
        );
    }

    public static function forBlankApprover(string $field): self
    {
        return new self(
            "A payout requires a non-blank approver [{$field}]. AC9 records the approver, ".
            'and an unnamed approver is not an approval.'
        );
    }
}
