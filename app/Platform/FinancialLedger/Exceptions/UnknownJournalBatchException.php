<?php

declare(strict_types=1);

namespace App\Platform\FinancialLedger\Exceptions;

use RuntimeException;

/**
 * Thrown when a reversal names a `business_key` no batch has.
 *
 * Loud on purpose: silently treating "there is nothing to reverse" as success
 * would let a correction be reported as applied while the ledger still carries
 * the original entries.
 */
final class UnknownJournalBatchException extends RuntimeException
{
    public static function forBusinessKey(string $businessKey): self
    {
        return new self(
            "No journal batch exists with business key [{$businessKey}]."
        );
    }
}
