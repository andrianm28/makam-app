<?php

declare(strict_types=1);

namespace App\Platform\FinancialLedger;

use InvalidArgumentException;

/**
 * The closed list of `reconciliation_exceptions.type` values (AC10).
 *
 * Every member is named from the LEDGER's point of view, because the ledger is
 * the record of what this business believes happened and the statement is
 * somebody else's claim about it. Getting that direction backwards would make
 * `missing` and `extra` mean each other, so it is stated per member rather than
 * left to a reader's intuition.
 *
 * A difference is a FINDING, never an instruction. Nothing in this module
 * adjusts `journal_batches`/`journal_entries` to make a statement agree — see
 * `Actions\RunReconciliation`'s doc block for why that is the defect this whole
 * feature exists to prevent.
 */
final class ReconciliationExceptionType
{
    /**
     * The journal and the statement both know this reference, and they
     * disagree about the amount. Both `journal_amount_minor` and
     * `statement_amount_minor` are recorded, and they genuinely differ (a
     * PostgreSQL CHECK enforces that a mismatch really is one).
     */
    public const string AMOUNT_MISMATCH = 'amount_mismatch';

    /**
     * The statement lists it; the journal has no batch for it. Missing FROM
     * THE JOURNAL. `journal_amount_minor` is NULL — not `0`, because we did
     * not observe a zero, we observed nothing.
     */
    public const string MISSING = 'missing';

    /**
     * The journal posted it; the statement does not list it. Extra IN THE
     * JOURNAL. `statement_amount_minor` is NULL, for the same reason.
     */
    public const string EXTRA = 'extra';

    /**
     * A journal batch in the period whose debits and credits do not agree.
     * Nothing to compare against the statement — an unbalanced batch has no
     * well-defined total — so `statement_amount_minor` is NULL and the batch
     * is deliberately excluded from the amount comparison rather than
     * producing a second, derivative finding.
     *
     * On PostgreSQL the `assert_balanced_batch` constraint trigger (Task 2)
     * makes this unreachable through normal writes. It is still detected here
     * because reconciliation is a DETECTIVE control: a restored backup, a
     * hand-repaired row, or a period migrated in from elsewhere can produce
     * one, and a detective control that trusts the preventive control has
     * stopped detecting.
     */
    public const string UNBALANCED = 'unbalanced';

    /**
     * @var list<string>
     */
    public const array KNOWN_TYPES = [
        self::AMOUNT_MISMATCH,
        self::MISSING,
        self::EXTRA,
        self::UNBALANCED,
    ];

    public static function isKnown(string $type): bool
    {
        return in_array($type, self::KNOWN_TYPES, true);
    }

    /**
     * @throws InvalidArgumentException when `$type` is not one of
     *                                  `self::KNOWN_TYPES`.
     */
    public static function assertKnown(string $type): void
    {
        if (! self::isKnown($type)) {
            throw new InvalidArgumentException(
                "Unknown reconciliation exception type [{$type}]. Known types: "
                .implode(', ', self::KNOWN_TYPES).'.'
            );
        }
    }
}
