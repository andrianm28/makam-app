<?php

declare(strict_types=1);

namespace App\Platform\FinancialLedger\Exceptions;

use InvalidArgumentException;

/**
 * A vendor payable was rejected before any row was written, because its shape
 * could never describe a real debt.
 *
 * Messages carry identifiers and minor-unit amounts only. No customer name, no
 * bank detail, no document content ever reaches an exception message
 * (`AGENTS.md` §Observability) — the same discipline
 * `InvalidJournalBatchException` documents for its own messages.
 */
final class InvalidVendorPayableException extends InvalidArgumentException
{
    public static function forNegativeAmount(int $amountMinor): self
    {
        return new self(
            "Vendor payable amount [{$amountMinor}] is negative. A payable is what ".
            'we owe; a negative debt is a receivable and belongs on a different '.
            'account, not on this row with its sign flipped.'
        );
    }

    public static function forBlankIdentifier(string $field): self
    {
        return new self(
            "Vendor payable requires a non-blank [{$field}]."
        );
    }

    public static function forAmountChangeAfterEligibility(
        string $payableId,
        int $currentAmountMinor,
        int $proposedAmountMinor,
    ): self {
        return new self(
            "Vendor payable [{$payableId}] is already eligible at [{$currentAmountMinor}] ".
            "minor units and cannot be re-assessed at [{$proposedAmountMinor}]. Changing ".
            'what we owe after we have recognised it is a correction, which belongs in '.
            'a new payable and a journal entry, not a silent rewrite of this row.'
        );
    }
}
