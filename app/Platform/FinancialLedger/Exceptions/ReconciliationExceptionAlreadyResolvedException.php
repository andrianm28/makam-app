<?php

declare(strict_types=1);

namespace App\Platform\FinancialLedger\Exceptions;

use RuntimeException;

/**
 * A second decision was attempted on an exception a human has already decided.
 *
 * Refused rather than allowed, on the same reasoning
 * `JournalBatchAlreadyReversedException` records for a second reversal: the
 * first decision, its decider, its moment and its reason are the audit trail
 * for what was concluded and by whom. Overwriting them would replace evidence
 * with a later opinion and leave no trace that the first decision ever existed
 * — and if the first decision was `post_correction`, the correction it posted
 * is still sitting in the ledger, so a silent overwrite would leave a batch
 * nothing explains.
 *
 * A finding that genuinely recurs is a NEW exception on a new run, with its own
 * row and its own decision. A first decision that was wrong is corrected the
 * way every other financial mistake in this module is: forward, by posting, not
 * by rewriting.
 */
final class ReconciliationExceptionAlreadyResolvedException extends RuntimeException
{
    public static function forException(string $exceptionId, string $decision): self
    {
        return new self(
            "Reconciliation exception [{$exceptionId}] was already resolved with decision "
            ."[{$decision}]. A recorded decision, its decider and its moment are never "
            .'overwritten; record a new finding instead.'
        );
    }
}
