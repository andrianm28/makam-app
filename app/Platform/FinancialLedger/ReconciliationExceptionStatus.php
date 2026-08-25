<?php

declare(strict_types=1);

namespace App\Platform\FinancialLedger;

use InvalidArgumentException;

/**
 * The closed list of `reconciliation_exceptions.status` values (AC10, AC12).
 *
 * ---------------------------------------------------------------------------
 * This column IS mutable, and that is deliberate
 * ---------------------------------------------------------------------------
 * The Wave 1b ruling that forbids `UPDATE` on `journal_batches` and
 * `journal_entries` is scoped to the JOURNAL tables. It does not extend here,
 * for exactly the reason `vendor_payables`' migration already records: the
 * journal is the immutable money record, while an exception is a workflow row
 * describing a finding that genuinely moves from "found" to "decided".
 *
 * There are two values and no third. There is no `closed`, no `expired`, no
 * `auto_resolved`, and no `stale` — because AC10 is explicit that an exception
 * is resolved ONLY by an authorised human decision recorded through
 * `Actions\ResolveException`. A value meaning "it went away on its own" is
 * exactly the escape hatch that would let a period closure quietly clear the
 * board, so the vocabulary for it does not exist.
 *
 * ---------------------------------------------------------------------------
 * Designed so Task 6 can revoke UPDATE after `resolved`
 * ---------------------------------------------------------------------------
 * The only transition anything in this module performs is `open -> resolved`,
 * once, guarded by a row lock and refused on a second attempt
 * (`ReconciliationExceptionAlreadyResolvedException`). Nothing reads, rewrites
 * or re-decides a row that already reached `resolved`, so Task 6 can revoke
 * UPDATE on a resolved exception at the database role level without breaking
 * any path here.
 */
final class ReconciliationExceptionStatus
{
    /**
     * Found, and awaiting an authorised decision. The value every exception is
     * created with, and the only value a decision may be applied to.
     */
    public const string OPEN = 'open';

    /**
     * An authorised human decided on it, with a recorded reason, a recorded
     * decider, a recorded moment, and a `RECONCILIATION_EXCEPTION_RESOLVED`
     * audit event.
     */
    public const string RESOLVED = 'resolved';

    /**
     * @var list<string>
     */
    public const array KNOWN_STATUSES = [
        self::OPEN,
        self::RESOLVED,
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
                "Unknown reconciliation exception status [{$status}]. Known statuses: "
                .implode(', ', self::KNOWN_STATUSES).'.'
            );
        }
    }
}
