<?php

declare(strict_types=1);

namespace App\Platform\FinancialLedger\Exceptions;

use InvalidArgumentException;

/**
 * A batch was rejected before any row was written, because its shape could
 * never produce a valid ledger record.
 *
 * These checks are a convenience for a legible error message, never the
 * authority — `journal_entries`' own CHECK constraints and the
 * `assert_balanced_batch` constraint trigger are (AC1). The one exception is
 * `forEmptyEntries()`: a batch with no entries fires no row trigger at all, so
 * the database authority never sees it, which makes that check structural
 * rather than duplicative.
 *
 * Every message here carries account codes, directions, and minor-unit
 * amounts only. Restricted data never reaches an exception message
 * (`AGENTS.md` §Observability).
 */
final class InvalidJournalBatchException extends InvalidArgumentException
{
    public static function forUnprefixedBusinessKey(string $businessKey): self
    {
        return new self(
            "Journal business key [{$businessKey}] is not source-prefixed. ".
            'Expected a documented format such as payment:{provider_event_id} '.
            'or renewal:{grave_record_id}:{period}.'
        );
    }

    public static function forEmptyEntries(string $businessKey): self
    {
        return new self(
            "Journal batch [{$businessKey}] has no entries. A batch with no ".
            'entries can never be balanced and never reaches the database '.
            'balance trigger.'
        );
    }

    public static function forInvalidDirection(int $index, mixed $direction): self
    {
        return new self(
            "Journal entry #{$index} has direction [".self::describe($direction).'], '.
            'which is not exactly DR or CR.'
        );
    }

    public static function forInvalidAmount(int $index, mixed $amountMinor): self
    {
        return new self(
            "Journal entry #{$index} has amount [".self::describe($amountMinor).'], '.
            'which is not a non-negative integer number of minor units. The '.
            'sign of a journal entry lives in its direction, never in its amount.'
        );
    }

    public static function forMissingAccount(int $index): self
    {
        return new self(
            "Journal entry #{$index} has no account code."
        );
    }

    /**
     * @param  list<string>  $accountCodes
     */
    public static function forUnknownAccounts(array $accountCodes): self
    {
        return new self(
            'Journal entries reference account codes not present in '.
            'coa_accounts: ['.implode(', ', $accountCodes).'].'
        );
    }

    public static function forMissingReversalReason(string $originalBusinessKey): self
    {
        return new self(
            "Reversing journal batch [{$originalBusinessKey}] requires a non-blank reason."
        );
    }

    private static function describe(mixed $value): string
    {
        return match (true) {
            is_string($value) => $value,
            is_int($value), is_float($value) => (string) $value,
            is_bool($value) => $value ? 'true' : 'false',
            $value === null => 'null',
            default => get_debug_type($value),
        };
    }
}
