<?php

declare(strict_types=1);

namespace App\Platform\FinancialLedger;

use App\Platform\FinancialLedger\Exceptions\InvalidReconciliationException;

/**
 * A provider statement for one period and one `badan usaha`, as an immutable
 * input to `Actions\RunReconciliation`.
 *
 * ---------------------------------------------------------------------------
 * There is no live provider-statement adapter, and this class is not one
 * ---------------------------------------------------------------------------
 * No provider statement source is wired in this repository, and building one is
 * a later HUMAN/provider decision (Task 5 brief, "NOT TESTED / out of scope").
 * This is the SHAPE a statement has to arrive in — a record or fixture handed to
 * the Action — not a client, not a parser, and not evidence that a real
 * statement can be fetched today. Anything that reports otherwise is
 * overstating what exists.
 *
 * ---------------------------------------------------------------------------
 * References only, never restricted data
 * ---------------------------------------------------------------------------
 * `$reference` is an OPAQUE provider-side identifier for the statement
 * document, and each line key is an opaque reference for one settled item. No
 * bank detail, no account number, no card number, no customer name or identity
 * ever enters this object, because everything here is copied onto
 * `reconciliations`/`reconciliation_exceptions` rows that reports and exports
 * read (`AGENTS.md` §Observability). Callers constructing one from a real
 * provider payload are responsible for projecting it down to references and
 * integer minor units first.
 *
 * ---------------------------------------------------------------------------
 * Money is integer minor units (AC11)
 * ---------------------------------------------------------------------------
 * `$lines` maps reference => integer minor units. `is_int` and not a cast, for
 * the same reason `Journal::post()` refuses a float: a weak caller passing
 * `1234.00` or `'1234'` must be rejected rather than silently truncated into a
 * financial finding.
 */
final readonly class ProviderStatement
{
    /**
     * @param  string  $reference  Opaque provider-side reference for the
     *                             statement document itself. Non-blank: a statement nothing can
     *                             point back at is not usable evidence in a dispute.
     * @param  string  $period  `YYYY-MM`, matched against the period the
     *                          reconciliation is being run for.
     * @param  int|string  $entityRef  The `badan usaha` this statement covers.
     * @param  array<string, int>  $lines  Opaque line reference => integer
     *                                     minor units. Keys are compared against `journal_batches.business_key`.
     *
     * @throws InvalidReconciliationException on a blank reference, a blank
     *                                        line reference, or a non-integer/negative line amount.
     */
    public function __construct(
        public string $reference,
        public string $period,
        public int|string $entityRef,
        public array $lines = [],
    ) {
        if (trim($this->reference) === '') {
            throw InvalidReconciliationException::forBlankStatementReference();
        }

        foreach ($this->lines as $lineReference => $amountMinor) {
            if (trim((string) $lineReference) === '') {
                throw InvalidReconciliationException::forBlankStatementLineReference();
            }

            if (! is_int($amountMinor) || $amountMinor < 0) {
                throw InvalidReconciliationException::forInvalidStatementLineAmount(
                    (string) $lineReference,
                    $amountMinor,
                );
            }
        }
    }

    public function totalMinor(): int
    {
        return array_sum($this->lines);
    }

    /**
     * @return array<string, int>
     */
    public function lines(): array
    {
        /** @var array<string, int> $lines */
        $lines = [];

        foreach ($this->lines as $lineReference => $amountMinor) {
            $lines[(string) $lineReference] = $amountMinor;
        }

        return $lines;
    }
}
