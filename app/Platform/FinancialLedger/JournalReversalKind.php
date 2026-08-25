<?php

declare(strict_types=1);

namespace App\Platform\FinancialLedger;

/**
 * The two kinds of reversing batch this ledger posts, per the plan's
 * documented business-key formats (`reversal:{original}` and
 * `refund:{original}`).
 *
 * An enum rather than a string parameter because the prefix and the new
 * batch's `source_type` must always agree, and both are separately constrained
 * by Task 2's PostgreSQL CHECKs — the business-key prefix check and the
 * `source_type` closed list. Binding them to one case here means a caller
 * cannot post a `refund:`-prefixed batch whose `source_type` says `reversal`,
 * and cannot invent a third prefix that the database would then reject at
 * insert time with a far less legible error.
 *
 * `reversal` is the accounting correction (the money movement should not have
 * been recorded); `refund` is the economic event of giving money back. They
 * post identical entries and differ only in what the ledger says happened,
 * which is precisely why the caller has to choose.
 */
enum JournalReversalKind: string
{
    case Reversal = 'reversal';

    case Refund = 'refund';

    /**
     * The reversing batch's own unique business key. Prefixing the original
     * key keeps the pair greppable, and makes an identical second reversal of
     * the same kind collide on the `business_key` UNIQUE constraint.
     */
    public function businessKeyFor(string $originalBusinessKey): string
    {
        return $this->value.':'.$originalBusinessKey;
    }

    /**
     * Both cases are members of `journal_batches`' `source_type` closed list,
     * which Task 2's CHECK constraint owns; this method does not restate that
     * list, it only maps onto it.
     */
    public function sourceType(): string
    {
        return $this->value;
    }
}
