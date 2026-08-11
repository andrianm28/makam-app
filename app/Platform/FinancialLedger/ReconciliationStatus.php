<?php

declare(strict_types=1);

namespace App\Platform\FinancialLedger;

use InvalidArgumentException;

/**
 * The closed list of `reconciliations.status` values (AC10).
 *
 * ---------------------------------------------------------------------------
 * `statement_missing` is the reason this list has three members, not two
 * ---------------------------------------------------------------------------
 * A period whose provider statement could not be fetched is NOT a period that
 * reconciled, and it is NOT a period whose statement totalled zero. Defaulting
 * an absent statement to `0` and reporting the whole journal as a mismatch
 * against it manufactures a finding nobody can act on and hides the real
 * problem, which is that we do not have the statement.
 *
 * `AGENTS.md` and `docs/design/design-system.md` §6 both forbid presenting a
 * partial period as complete, so the absence gets its own status value rather
 * than being folded into `matched` or into a mismatch. The migration restates
 * this at the database as
 * `(status = 'statement_missing') = (statement_total_minor IS NULL)`.
 *
 * ---------------------------------------------------------------------------
 * What `matched` does and does not claim
 * ---------------------------------------------------------------------------
 * `matched` means "no OPEN exception remains for this period", not "no
 * difference was ever found". A difference that a human decided on through
 * `Actions\ResolveException` is a decided difference, not a nonexistent one —
 * its `reconciliation_exceptions` row stays on file forever with its decision,
 * its decider and its reason.
 *
 * Not a native PHP enum, for the same reason `VendorPayableState` is not: the
 * value is stored in a string column and a PostgreSQL CHECK constraint is the
 * real authority. See that class's own doc block for the full precedent.
 */
final class ReconciliationStatus
{
    /**
     * The provider statement for this period could not be fetched at all.
     * Never rendered as a completed or partial period.
     */
    public const string STATEMENT_MISSING = 'statement_missing';

    /**
     * At least one `reconciliation_exceptions` row for this period is still
     * `open` and awaiting an authorised human decision.
     */
    public const string EXCEPTIONS_OPEN = 'exceptions_open';

    /**
     * A statement was compared and no open exception remains. See the class
     * doc block for what this deliberately does not claim.
     */
    public const string MATCHED = 'matched';

    /**
     * @var list<string>
     */
    public const array KNOWN_STATUSES = [
        self::STATEMENT_MISSING,
        self::EXCEPTIONS_OPEN,
        self::MATCHED,
    ];

    public static function isKnown(string $status): bool
    {
        return in_array($status, self::KNOWN_STATUSES, true);
    }

    /**
     * @throws InvalidArgumentException when `$status` is not one of
     *                                  `self::KNOWN_STATUSES`.
     */
    public static function assertKnown(string $status): void
    {
        if (! self::isKnown($status)) {
            throw new InvalidArgumentException(
                "Unknown reconciliation status [{$status}]. Known statuses: "
                .implode(', ', self::KNOWN_STATUSES).'.'
            );
        }
    }
}
