<?php

declare(strict_types=1);

namespace App\Domain\Quotation\Exceptions;

use RuntimeException;

/**
 * Thrown by `Models\Quote` and `Models\QuoteLine`'s write guards — the
 * application-level half of AC8 ("THE SYSTEM SHALL NOT modify a quote after
 * it is issued") for `quotes` / `quote_lines`. See `Models\Quote`'s class
 * doc block for exactly what the overrides close and what they cannot (a
 * raw `DB::table()` write or a builder-level mass update still bypasses
 * them; the database-level close is not part of this task).
 *
 * A quote version is frozen the moment it is persisted. The only legal
 * writes are the two transitions `Actions\IssueQuote` / `Actions\AcceptQuote`
 * perform through the model's authorized `supersede()` / `accept()` doors.
 */
final class IssuedQuoteIsImmutableException extends RuntimeException
{
    public static function forQuote(int|string|null $quoteId, string $operation): self
    {
        return new self(
            "quotes row [{$quoteId}] is immutable after issuance; ".
            "[{$operation}] is not permitted. The only legal transitions are ".
            'issued -> accepted (Actions\AcceptQuote) and the current version -> '.
            'superseded (Actions\IssueQuote when a newer version is issued).'
        );
    }

    public static function forQuoteLine(int|string|null $quoteLineId, string $operation): self
    {
        return new self(
            "quote_lines row [{$quoteLineId}] is write-once; [{$operation}] is not permitted. ".
            'To change a quoted amount, issue a new quote version.'
        );
    }
}
