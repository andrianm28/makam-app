<?php

declare(strict_types=1);

namespace Tests\Feature\FinancialLedger;

use App\Platform\FinancialLedger\Contracts\Journal as JournalContract;
use App\Platform\FinancialLedger\Journal;
use App\Platform\FinancialLedger\Models\JournalBatch;
use App\Platform\FinancialLedger\Models\JournalEntry;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Tests\TestCase;

/**
 * Task 7, Step 2 — the L4-owned half of the cross-lane ledger seam check.
 *
 * `tests/Contract/LedgerSeamContractTest.php` is `lane/l3-payment-adapter`'s
 * artifact, copied into this branch byte-for-byte so both lanes run the
 * identical file. Its four BEHAVIOURAL cases run against
 * `Tests\Contract\Support\LedgerSeamStub`, a hand-written stub — so on its own
 * it never touches this lane's real `Journal` at all. This file asserts the
 * same four properties against the CONTAINER-RESOLVED real implementation,
 * which also makes it the proof that Task 7's
 * `FinancialLedgerServiceProvider` binding is live: every case here resolves
 * `app(Contracts\Journal::class)` and would fail with a
 * `BindingResolutionException` if that binding were dropped.
 *
 * ---------------------------------------------------------------------------
 * WHERE THE REAL IMPLEMENTATION DIVERGES FROM L3's STUB — read before editing
 * ---------------------------------------------------------------------------
 * All four properties HOLD against the real `Journal`, but two of them hold
 * with a different exception TYPE than the stub raises, and one of those two
 * holds only on PostgreSQL. This is not drift and it is not something to
 * "fix" by relaxing an assertion here — it follows from the shared interface's
 * own contract terms, which the stub does not keep:
 *
 *  - **Imbalance.** `Contracts\Journal` term 4 says "the database is the
 *    balance authority (AC1) ... an implementation may validate shape for a
 *    better error message, but MUST NOT present itself as the balance gate."
 *    The real `Journal` therefore performs no debit/credit sum; PostgreSQL's
 *    `assert_balanced_batch` constraint trigger rejects the batch and the
 *    caller sees a `QueryException`. SQLite has no such trigger, so on SQLite
 *    an unbalanced batch is NOT rejected at all and that case is skipped
 *    explicitly, exactly as `JournalPostTest` and `JournalSchemaTest` do.
 *    `LedgerSeamStub` sums the entries itself and throws
 *    `InvalidArgumentException` — i.e. the stub does precisely what term 4
 *    forbids an implementation from doing.
 *  - **Duplicate business key.** Term 2 says "a colliding `business_key` posts
 *    nothing (AC5). The INSERT throws" — the UNIQUE constraint is the
 *    authority, so the real type is again `QueryException`, on both drivers.
 *    The stub throws `InvalidArgumentException` from an in-memory key set.
 *
 * The consequence for `lane/l3-payment-adapter` is recorded as an escalation
 * in `task-7-report.md`: a consumer that learned from the stub to write
 * `catch (InvalidArgumentException)` around `post()` for at-least-once
 * redelivery would not catch anything against the real implementation. No L3
 * application code consumes this seam yet, so it is a latent trap rather than
 * a live defect — which is exactly why it is worth pinning here now.
 *
 * The deliberate asymmetry the ledger records is left alone: `post()` opens no
 * transaction, `postReversal()` DOES own one internally (a Task 4 review
 * finding required the reversal insert and its `JOURNAL_REVERSAL` audit row to
 * be atomic). Only `post()` carries the no-transaction term, so only `post()`
 * is asserted for it.
 */
final class LedgerSeamRealImplementationTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_seam_resolves_from_the_container_to_this_lanes_real_journal(): void
    {
        $resolved = $this->app->make(JournalContract::class);

        $this->assertInstanceOf(Journal::class, $resolved);
    }

    /**
     * Contract property 1 — an unbalanced entry set is rejected.
     *
     * PostgreSQL only, because the rejection is the database's (term 4). The
     * skip is the honest report of a property this driver cannot exercise, not
     * a weakened assertion: on SQLite the batch is written.
     */
    public function test_the_real_implementation_rejects_an_unbalanced_entry_set(): void
    {
        $this->skipUnlessPostgres(
            'The assert_balanced_batch constraint trigger is PostgreSQL-only; run this test with DB_CONNECTION=pgsql.'
        );

        // QueryException, not InvalidArgumentException — see this class's doc
        // block. Asserting InvalidArgumentException here would be asserting
        // that Journal is the balance gate, which the shared interface
        // explicitly forbids.
        $this->expectException(QueryException::class);

        DB::transaction(function (): void {
            $this->journal()->post(
                businessKey: 'payment:seam-unbalanced',
                entityRef: 'badan-usaha-1',
                sourceType: 'payment',
                sourceId: 'seam-unbalanced',
                entries: [
                    ['account' => '7000', 'direction' => 'DR', 'amountMinor' => 1_000],
                    ['account' => '4000', 'direction' => 'CR', 'amountMinor' => 999],
                ],
            );
        });
    }

    /**
     * Contract property 2 — a duplicate business key is rejected, and posts
     * nothing (AC5). Holds on both drivers: the UNIQUE constraint on
     * `journal_batches.business_key` exists everywhere.
     *
     * The redelivery is wrapped in the caller's own `DB::transaction()`, which
     * is both the contract's prescribed shape (term 1) and a hard requirement
     * on PostgreSQL: a constraint violation aborts the whole enclosing
     * transaction there, so without a savepoint to roll back to, every
     * assertion after the catch fails with `25P02 current transaction is
     * aborted`. SQLite does not behave that way, so an unwrapped version of
     * this test passes on SQLite and errors on PostgreSQL — which is why the
     * PostgreSQL leg is run.
     */
    public function test_the_real_implementation_rejects_a_duplicate_business_key(): void
    {
        $this->journal()->post(
            businessKey: 'payment:seam-duplicate',
            entityRef: 'badan-usaha-1',
            sourceType: 'payment',
            sourceId: 'seam-duplicate',
            entries: $this->balancedEntries(),
        );

        try {
            DB::transaction(function (): void {
                $this->journal()->post(
                    businessKey: 'payment:seam-duplicate',
                    entityRef: 'badan-usaha-1',
                    sourceType: 'payment',
                    sourceId: 'seam-duplicate',
                    entries: $this->balancedEntries(),
                );
            });

            $this->fail('Expected the duplicate business key to be refused.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }

        // "Posts nothing" is the substantive half of the property — the
        // exception alone would not rule out a second batch having landed.
        $this->assertSame(
            1,
            JournalBatch::query()->where('business_key', 'payment:seam-duplicate')->count(),
        );
        $this->assertSame(2, JournalEntry::query()->count());
    }

    /**
     * Contract property 3 — `post()` opens no transaction of its own (term 1,
     * AC3). `TransactionBeginning` fires for a savepoint as well as a real
     * BEGIN, so a count of zero rules out both.
     */
    public function test_the_real_implementations_post_opens_no_transaction_of_its_own(): void
    {
        $begun = 0;
        Event::listen(TransactionBeginning::class, static function () use (&$begun): void {
            $begun++;
        });

        $levelBefore = DB::transactionLevel();

        $this->journal()->post(
            businessKey: 'payment:seam-no-transaction',
            entityRef: 'badan-usaha-1',
            sourceType: 'payment',
            sourceId: 'seam-no-transaction',
            entries: $this->balancedEntries(),
        );

        $this->assertSame(0, $begun);
        // Compared against itself, not against zero: RefreshDatabase already
        // holds a transaction open around every test in this suite.
        $this->assertSame($levelBefore, DB::transactionLevel());
    }

    /**
     * Contract property 4 — a caller can wrap `post()` in its own transaction,
     * and the write participates in it rather than committing beside it.
     *
     * Asserted in both directions. The commit half proves the wrap is legal at
     * all; the rollback half is what proves participation — a `post()` that
     * had quietly opened and committed its own transaction would survive the
     * caller's rollback and pass a commit-only test.
     */
    public function test_a_caller_can_wrap_the_real_implementations_post_in_its_own_transaction(): void
    {
        $levelOutside = DB::transactionLevel();
        $observedInside = null;

        DB::transaction(function () use ($levelOutside, &$observedInside): void {
            $this->journal()->post(
                businessKey: 'payment:seam-caller-transaction',
                entityRef: 'badan-usaha-1',
                sourceType: 'payment',
                sourceId: 'seam-caller-transaction',
                entries: $this->balancedEntries(),
            );

            // Exactly one level deeper than the caller started at: the wrap is
            // the caller's own and `post()` added nothing underneath it.
            $observedInside = DB::transactionLevel() - $levelOutside;
        });

        $this->assertSame(1, $observedInside);
        $this->assertSame(
            1,
            JournalBatch::query()->where('business_key', 'payment:seam-caller-transaction')->count(),
        );
    }

    public function test_the_real_implementations_post_is_rolled_back_with_the_callers_transaction(): void
    {
        try {
            DB::transaction(function (): void {
                $this->journal()->post(
                    businessKey: 'payment:seam-caller-rollback',
                    entityRef: 'badan-usaha-1',
                    sourceType: 'payment',
                    sourceId: 'seam-caller-rollback',
                    entries: $this->balancedEntries(),
                );

                throw new RuntimeException('simulated caller failure before commit');
            });

            $this->fail('Expected the simulated caller failure to propagate.');
        } catch (RuntimeException $exception) {
            $this->assertSame('simulated caller failure before commit', $exception->getMessage());
        }

        $this->assertSame(
            0,
            JournalBatch::query()->where('business_key', 'payment:seam-caller-rollback')->count(),
        );
        $this->assertSame(0, JournalEntry::query()->count());
    }

    /**
     * @return list<array{account: string, direction: string, amountMinor: int}>
     */
    private function balancedEntries(): array
    {
        return [
            ['account' => '7000', 'direction' => 'DR', 'amountMinor' => 1_000],
            ['account' => '4000', 'direction' => 'CR', 'amountMinor' => 1_000],
        ];
    }

    private function journal(): JournalContract
    {
        return $this->app->make(JournalContract::class);
    }

    private function skipUnlessPostgres(string $message): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped($message);
        }
    }
}
