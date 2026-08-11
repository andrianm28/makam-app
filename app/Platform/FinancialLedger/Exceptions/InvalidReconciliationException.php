<?php

declare(strict_types=1);

namespace App\Platform\FinancialLedger\Exceptions;

use InvalidArgumentException;

/**
 * A reconciliation run or an exception resolution was refused on its own SHAPE
 * — before any reconciliation row, any exception row, any audit row and any
 * journal batch was written.
 *
 * Kept separate from `ReconciliationNotAuthorisedException` ("you may not do
 * this") and `ReconciliationExceptionAlreadyResolvedException` ("someone
 * already decided this"), following the three-way split
 * `InvalidPayoutException` established in this module: a caller and a test can
 * tell a malformed request from a refused one from a duplicate one, which are
 * three different things to show an operator and three different things to
 * alert on.
 *
 * Messages carry periods, references, type names and integer minor-unit amounts
 * only — never a bank detail, an account number or a customer identity
 * (`AGENTS.md` §Observability).
 */
final class InvalidReconciliationException extends InvalidArgumentException
{
    public static function forMalformedPeriod(string $period): self
    {
        return new self(
            "Reconciliation period [{$period}] is not a calendar month in `YYYY-MM` form. ".
            'A period has to name an exact, closed range of time, or the set of journal '.
            'batches it covers is undefined.'
        );
    }

    public static function forStatementPeriodMismatch(string $expected, string $actual): self
    {
        return new self(
            "The provided statement covers period [{$actual}] but reconciliation was ".
            "requested for [{$expected}]. Comparing a period against another period's ".
            'statement manufactures findings on both sides of the difference.'
        );
    }

    public static function forStatementEntityMismatch(string $expected, string $actual): self
    {
        return new self(
            "The provided statement covers entity [{$actual}] but reconciliation was ".
            "requested for [{$expected}]. AC4 binds every journal batch to an explicit ".
            'badan usaha; comparing across entities would mix two sets of books.'
        );
    }

    public static function forBlankEntityRef(): self
    {
        return new self(
            'Reconciliation requires a non-blank badan usaha reference. An unbound '.
            'reconciliation would silently span every entity.'
        );
    }

    public static function forBlankStatementReference(): self
    {
        return new self(
            'A provider statement requires a non-blank opaque reference. A statement '.
            'nothing can point back at is not usable evidence in a dispute.'
        );
    }

    public static function forBlankStatementLineReference(): self
    {
        return new self(
            'Every provider statement line requires a non-blank opaque reference; it is '.
            'the only thing a finding about that line can be identified by.'
        );
    }

    public static function forInvalidStatementLineAmount(string $lineReference, mixed $amountMinor): self
    {
        $rendered = is_scalar($amountMinor) ? var_export($amountMinor, true) : get_debug_type($amountMinor);

        return new self(
            "Provider statement line [{$lineReference}] carries amount [{$rendered}], which is ".
            'not a non-negative integer number of minor units. AC11 keeps money in integer '.
            'minor units end to end; a float or decimal string would be truncated into a '.
            'financial finding rather than rejected.'
        );
    }

    public static function forCorrectionWithoutPostCorrection(string $decision): self
    {
        return new self(
            "A corrective journal posting was supplied with decision [{$decision}]. ".
            'Only [post_correction] posts a batch; supplying one alongside any other '.
            'decision would post money movement the recorded decision does not describe.'
        );
    }

    public static function forPostCorrectionWithoutCorrection(): self
    {
        return new self(
            'Decision [post_correction] requires the corrective batch to be supplied with '.
            'it, so the resolution and the correction commit or roll back together. '.
            'Recording the decision now and posting the correction afterwards is exactly '.
            'the gap where a resolved exception ends up with no correction behind it.'
        );
    }

    public static function forBlankCorrectionBusinessKey(): self
    {
        return new self(
            'A corrective journal posting requires a non-blank business key; it is the '.
            'idempotency key that stops an at-least-once retry double-posting it.'
        );
    }

    /**
     * Slice-3 Minor M5, closed at this seam and deliberately NOT at
     * `Journal::post()` — see `ReconciliationCorrection::adjustment()` for why
     * the shared cross-lane seam was left alone.
     */
    public static function forCorrectionSourceTypeMismatch(
        string $businessKey,
        string $prefix,
        string $sourceType,
    ): self {
        return new self(
            "Corrective posting business key [{$businessKey}] is prefixed [{$prefix}] but declares "
            ."source type [{$sourceType}]. A corrective batch must announce the same kind of event "
            .'in both places, or the ledger records it as one thing and is keyed as another.'
        );
    }

    public static function forEmptyCorrectionEntries(string $businessKey): self
    {
        return new self(
            "Corrective batch [{$businessKey}] has no entries. A batch with no entry rows ".
            'fires no balance trigger at all, so an empty one would post silently.'
        );
    }

    public static function forBlankCorrectionSubjectReference(): self
    {
        return new self(
            'A corrective journal posting requires the reconciliation exception subject reference; '
            .'without it, the correction cannot be bound to the finding it resolves.'
        );
    }

    public static function forCorrectionEntityMismatch(string $expected, string $actual): self
    {
        return new self(
            "Corrective posting entity [{$actual}] does not match the authorized reconciliation "
            ."exception entity [{$expected}]."
        );
    }

    public static function forCorrectionSubjectMismatch(string $expected, string $actual): self
    {
        return new self(
            "Corrective posting subject [{$actual}] does not match the reconciliation exception "
            ."subject [{$expected}]."
        );
    }

    public static function forCorrectionJournalEntityMismatch(string $expected, string $actual): self
    {
        return new self(
            "The journal batch for reconciliation subject entity [{$actual}] does not belong to "
            ."authorized entity [{$expected}]."
        );
    }
}
