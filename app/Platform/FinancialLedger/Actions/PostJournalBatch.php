<?php

declare(strict_types=1);

namespace App\Platform\FinancialLedger\Actions;

use App\Platform\FinancialLedger\Models\JournalBatch;
use Illuminate\Support\Facades\DB;

/**
 * The insert mechanism behind `App\Platform\FinancialLedger\Journal` — the
 * only place in this codebase that writes `journal_batches` and
 * `journal_entries` rows. `Journal` is the public seam and owns validation,
 * business-key format, correlation, and reversal derivation; this Action owns
 * nothing but the two inserts, so neither duplicates the other.
 *
 * ---------------------------------------------------------------------------
 * Opens no transaction, catches nothing
 * ---------------------------------------------------------------------------
 * Both inserts run on the caller's connection, in whatever transaction the
 * caller already opened (AC3). If the batch insert throws — a colliding
 * `business_key`, AC5's at-least-once-delivery case — the entries insert never
 * runs and the caller's transaction rolls back. If the entries insert throws —
 * PostgreSQL's `assert_balanced_batch` constraint trigger rejecting an
 * unbalanced batch (AC1) — the caller's transaction rolls back with the batch
 * row still uncommitted. Nothing here is caught, softened, or retried.
 *
 * Note what that means when there is NO caller transaction: the batch row and
 * the entry rows are two separate statements, so a failure between them leaves
 * a committed batch with no entries. That is not a hole this Action can close
 * on its own — it is the reason the seam's contract requires a caller
 * transaction, stated plainly rather than papered over with a transaction
 * opened here that would defeat AC3.
 *
 * ---------------------------------------------------------------------------
 * Query builder, not Eloquent `create()`
 * ---------------------------------------------------------------------------
 * `JournalBatch` and `JournalEntry` are `$guarded = ['*']` by design (Task 2),
 * so mass assignment is closed on purpose and `create()` would write nothing.
 * More importantly the entries must go in as ONE multi-row statement: the
 * balance trigger is `AFTER INSERT ... FOR EACH ROW DEFERRABLE INITIALLY
 * IMMEDIATE`, so its checks run at the end of the statement — a balanced
 * two-row insert passes, while the same two rows inserted one statement at a
 * time would fail on the first. `tests/Feature/FinancialLedger/JournalSchemaTest.php`
 * proves that behaviour against PostgreSQL 18.
 *
 * `currency` is deliberately absent from the rows built here. The column's own
 * `IDR` default plus Task 2's PostgreSQL CHECK are the single source of that
 * value; restating it in application code would duplicate canonical data
 * (`AGENTS.md` §Documentation).
 */
final readonly class PostJournalBatch
{
    /**
     * @param  array{id: string, business_key: string, entity_ref: string, source_type: string, source_id: string, correlation_id: string|null, occurred_at: mixed, status: string, reverses_batch_id: string|null}  $batch
     * @param  non-empty-list<array{batch_id: string, account_code: string, direction: string, amount_minor: int, reference: string|null}>  $entries
     */
    public function __invoke(array $batch, array $entries): JournalBatch
    {
        DB::table('journal_batches')->insert($batch);
        DB::table('journal_entries')->insert($entries);

        return JournalBatch::query()->findOrFail($batch['id']);
    }
}
