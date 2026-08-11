<?php

declare(strict_types=1);

namespace App\Platform\FinancialLedger\Exceptions;

use RuntimeException;

/**
 * Thrown when a batch that already has a reversal is asked to be reversed
 * again. See `Journal::postReversal()`'s doc block for why a second reversal
 * is refused rather than allowed.
 */
final class JournalBatchAlreadyReversedException extends RuntimeException
{
    public static function forBusinessKey(string $originalBusinessKey, string $reversingBusinessKey): self
    {
        return new self(
            "Journal batch [{$originalBusinessKey}] is already reversed by ".
            "[{$reversingBusinessKey}]. Reversing it a second time would ".
            'double-count the correction. Post a new batch describing the '.
            'later economic event instead.'
        );
    }
}
