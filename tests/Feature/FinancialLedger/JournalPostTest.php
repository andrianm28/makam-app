<?php

declare(strict_types=1);

namespace Tests\Feature\FinancialLedger;

use App\Platform\Correlation\CorrelationContext;
use App\Platform\Correlation\CorrelationId;
use App\Platform\FinancialLedger\Exceptions\InvalidJournalBatchException;
use App\Platform\FinancialLedger\Journal;
use App\Platform\FinancialLedger\Models\JournalBatch;
use App\Platform\FinancialLedger\Models\JournalEntry;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Tests\Fixtures\CreatesOutboxFixtureAggregateTable;
use Tests\Fixtures\OutboxFixtureAggregate;
use Tests\TestCase;

/**
 * AC3 ("no journal write ever commits separately from the state change that
 * caused it") and AC5 ("a colliding business key posts nothing") for
 * `Journal::post()`.
 *
 * Mirrors `tests/Feature/Outbox/OutboxTransactionTest.php` and
 * `tests/Feature/Audit/AuditWrapTransactionTest.php`: the caller owns the
 * transaction, and the proof is that the caller's OWN state change is rolled
 * back alongside the journal rows. `Tests\Fixtures\OutboxFixtureAggregate` is
 * reused as the stand-in state change for exactly the reason its own doc
 * block gives — no real domain mutation posts to the ledger yet, because
 * `lane/l3-payment-adapter` is still in flight.
 *
 * AC1's balance rule is deliberately NOT asserted as an application-level
 * rejection here. The PostgreSQL `assert_balanced_batch` constraint trigger
 * (Task 2) is the authority; `Journal` does not re-implement it, so the
 * imbalance test below skips explicitly on SQLite exactly as
 * `JournalSchemaTest` does.
 */
final class JournalPostTest extends TestCase
{
    use CreatesOutboxFixtureAggregateTable;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpOutboxFixtureAggregateTable();
    }

    public function test_post_writes_the_batch_and_every_entry(): void
    {
        $batch = $this->journal()->post(
            businessKey: 'payment:provider-event-1',
            entityRef: 'badan-usaha-1',
            sourceType: 'payment',
            sourceId: 'provider-event-1',
            entries: [
                ['account' => '7000', 'direction' => 'DR', 'amountMinor' => 250_000, 'reference' => 'settlement'],
                ['account' => '4000', 'direction' => 'CR', 'amountMinor' => 250_000],
            ],
            correlationId: 'trace-post-1',
            occurredAt: '2026-08-09T10:00:00+07:00',
        );

        $row = DB::table('journal_batches')->where('id', $batch->id)->sole();

        $this->assertSame('payment:provider-event-1', $row->business_key);
        $this->assertSame('badan-usaha-1', $row->entity_ref);
        $this->assertSame('payment', $row->source_type);
        $this->assertSame('provider-event-1', $row->source_id);
        $this->assertSame('trace-post-1', $row->correlation_id);
        $this->assertSame('posted', $row->status);
        $this->assertNull($row->reverses_batch_id);

        $entries = JournalEntry::query()->where('batch_id', $batch->id)->orderBy('account_code')->get();

        $this->assertCount(2, $entries);
        $this->assertSame(['4000', '7000'], $entries->pluck('account_code')->all());
        $this->assertSame(['CR', 'DR'], $entries->pluck('direction')->all());
        $this->assertSame([250_000, 250_000], $entries->pluck('amount_minor')->all());
        $this->assertSame([null, 'settlement'], $entries->pluck('reference')->all());
        $this->assertSame('IDR', $entries->first()->currency);
        $this->assertTrue($batch->isBalanced());
        $this->assertSame(250_000, $batch->total());
    }

    public function test_journal_rows_do_not_exist_when_the_callers_transaction_rolls_back(): void
    {
        $aggregate = OutboxFixtureAggregate::create(['status' => 'draft']);

        try {
            DB::transaction(function () use ($aggregate): void {
                $aggregate->forceFill(['status' => 'paid'])->save();

                $this->journal()->post(
                    businessKey: 'payment:rolled-back-event',
                    entityRef: 'badan-usaha-1',
                    sourceType: 'payment',
                    sourceId: 'rolled-back-event',
                    entries: $this->balancedEntries(),
                );

                throw new RuntimeException('simulated failure after both writes, before commit');
            });

            $this->fail('Expected RuntimeException to propagate.');
        } catch (RuntimeException $exception) {
            $this->assertSame('simulated failure after both writes, before commit', $exception->getMessage());
        }

        // Without a shared transaction the aggregate's save() would already
        // have committed on its own before the journal write ran. This is the
        // actual AC3 proof, not the row counts alone.
        $this->assertSame('draft', $this->persistedAggregateStatus($aggregate));
        $this->assertSame(0, JournalBatch::query()->count());
        $this->assertSame(0, JournalEntry::query()->count());
    }

    public function test_post_opens_no_transaction_of_its_own(): void
    {
        $begun = 0;
        Event::listen(TransactionBeginning::class, function () use (&$begun): void {
            $begun++;
        });

        $levelBefore = DB::transactionLevel();

        $this->journal()->post(
            businessKey: 'payment:no-transaction-of-its-own',
            entityRef: 'badan-usaha-1',
            sourceType: 'payment',
            sourceId: 'no-transaction-of-its-own',
            entries: $this->balancedEntries(),
        );

        // AC3: `post()` must never open a transaction — nor nest a savepoint
        // inside the caller's — because either would let the journal rows
        // commit independently of the state change they belong to.
        // `TransactionBeginning` fires for both, so a count of zero covers
        // both. (`RefreshDatabase` already holds a transaction open around
        // every test in this suite, which is why the level is compared to
        // itself rather than asserted to be zero.)
        $this->assertSame(0, $begun);
        $this->assertSame($levelBefore, DB::transactionLevel());
        $this->assertSame(1, JournalBatch::query()->count());
    }

    public function test_a_duplicate_business_key_posts_nothing_and_rolls_the_caller_back(): void
    {
        $this->journal()->post(
            businessKey: 'payment:at-least-once-delivery',
            entityRef: 'badan-usaha-1',
            sourceType: 'payment',
            sourceId: 'at-least-once-delivery',
            entries: $this->balancedEntries(),
        );

        $aggregate = OutboxFixtureAggregate::create(['status' => 'draft']);

        try {
            DB::transaction(function () use ($aggregate): void {
                $aggregate->forceFill(['status' => 'paid'])->save();

                // AC5: the redelivered provider event carries the same
                // business key. The INSERT throws; nothing is caught, nothing
                // is upserted, nothing double-posts.
                $this->journal()->post(
                    businessKey: 'payment:at-least-once-delivery',
                    entityRef: 'badan-usaha-1',
                    sourceType: 'payment',
                    sourceId: 'at-least-once-delivery',
                    entries: $this->balancedEntries(),
                );
            });

            $this->fail('Expected a database constraint violation on the duplicate business key.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame('draft', $this->persistedAggregateStatus($aggregate));
        $this->assertSame(1, JournalBatch::query()->where('business_key', 'payment:at-least-once-delivery')->count());
        $this->assertSame(2, JournalEntry::query()->count());
    }

    public function test_post_rejects_an_account_that_is_not_in_the_chart_of_accounts(): void
    {
        $this->expectException(InvalidJournalBatchException::class);
        $this->expectExceptionMessageMatches('/9999/');

        $this->postWithEntries([
            ['account' => '9999', 'direction' => 'DR', 'amountMinor' => 1_000],
            ['account' => '4000', 'direction' => 'CR', 'amountMinor' => 1_000],
        ]);
    }

    public function test_post_rejects_a_direction_that_is_not_dr_or_cr(): void
    {
        $this->expectException(InvalidJournalBatchException::class);

        $this->postWithEntries([
            ['account' => '7000', 'direction' => 'dr', 'amountMinor' => 1_000],
            ['account' => '4000', 'direction' => 'CR', 'amountMinor' => 1_000],
        ]);
    }

    public function test_post_rejects_a_negative_amount_because_sign_lives_in_the_direction(): void
    {
        $this->expectException(InvalidJournalBatchException::class);

        $this->postWithEntries([
            ['account' => '7000', 'direction' => 'DR', 'amountMinor' => -1_000],
            ['account' => '4000', 'direction' => 'CR', 'amountMinor' => 1_000],
        ]);
    }

    public function test_post_rejects_a_non_integer_amount(): void
    {
        $this->expectException(InvalidJournalBatchException::class);

        $this->postWithEntries([
            // AC11: no float, no decimal string. A weak caller must not be
            // able to truncate its way into the ledger.
            ['account' => '7000', 'direction' => 'DR', 'amountMinor' => '1000'],
            ['account' => '4000', 'direction' => 'CR', 'amountMinor' => 1_000],
        ]);
    }

    public function test_post_rejects_an_empty_entry_list(): void
    {
        // A batch with no entries fires no row trigger at all, so the database
        // balance authority never sees it. Rejecting it here is a structural
        // check, not a re-implementation of the balance rule.
        $this->expectException(InvalidJournalBatchException::class);

        $this->postWithEntries([]);
    }

    public function test_post_rejects_a_business_key_with_no_source_prefix(): void
    {
        $this->expectException(InvalidJournalBatchException::class);

        $this->journal()->post(
            businessKey: 'no-prefix-here',
            entityRef: 'badan-usaha-1',
            sourceType: 'payment',
            sourceId: 'no-prefix-here',
            entries: $this->balancedEntries(),
        );
    }

    public function test_a_rejected_batch_leaves_no_partial_rows_behind(): void
    {
        try {
            $this->postWithEntries([
                ['account' => '7000', 'direction' => 'DR', 'amountMinor' => 1_000],
                ['account' => '9999', 'direction' => 'CR', 'amountMinor' => 1_000],
            ]);
            $this->fail('Expected InvalidJournalBatchException.');
        } catch (InvalidJournalBatchException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame(0, JournalBatch::query()->count());
        $this->assertSame(0, JournalEntry::query()->count());
    }

    public function test_post_falls_back_to_the_correlation_context_when_no_id_is_passed(): void
    {
        $this->app->make(CorrelationContext::class)->set(CorrelationId::fromString('trace-from-context'));

        $batch = $this->journal()->post(
            businessKey: 'payment:correlation-fallback',
            entityRef: 'badan-usaha-1',
            sourceType: 'payment',
            sourceId: 'correlation-fallback',
            entries: $this->balancedEntries(),
        );

        $this->assertSame('trace-from-context', $batch->correlation_id);
    }

    public function test_an_explicit_correlation_id_wins_over_the_context(): void
    {
        $this->app->make(CorrelationContext::class)->set(CorrelationId::fromString('trace-from-context'));

        $batch = $this->journal()->post(
            businessKey: 'payment:correlation-explicit',
            entityRef: 'badan-usaha-1',
            sourceType: 'payment',
            sourceId: 'correlation-explicit',
            entries: $this->balancedEntries(),
            correlationId: 'trace-explicit',
        );

        $this->assertSame('trace-explicit', $batch->correlation_id);
    }

    public function test_an_unbalanced_batch_is_rejected_by_the_database_not_the_application(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped(
                'The assert_balanced_batch constraint trigger is PostgreSQL-only; run this test with DB_CONNECTION=pgsql.'
            );
        }

        // Journal deliberately performs no application-level balance check —
        // the failure below must come from the database, which is why the
        // expected type is QueryException and not InvalidJournalBatchException.
        $this->expectException(QueryException::class);

        DB::transaction(function (): void {
            $this->postWithEntries([
                ['account' => '7000', 'direction' => 'DR', 'amountMinor' => 1_000],
                ['account' => '4000', 'direction' => 'CR', 'amountMinor' => 999],
            ]);
        });
    }

    /**
     * Read back from the row itself rather than from the in-memory model, so
     * the assertion is about what the database committed.
     */
    private function persistedAggregateStatus(OutboxFixtureAggregate $aggregate): mixed
    {
        return DB::table('outbox_fixture_aggregates')->where('id', $aggregate->getKey())->value('status');
    }

    /**
     * @param  list<array<string, mixed>>  $entries
     */
    private function postWithEntries(array $entries): JournalBatch
    {
        return $this->journal()->post(
            businessKey: 'payment:'.uniqid('event-', true),
            entityRef: 'badan-usaha-1',
            sourceType: 'payment',
            sourceId: 'event-1',
            entries: $entries,
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function balancedEntries(): array
    {
        return [
            ['account' => '7000', 'direction' => 'DR', 'amountMinor' => 100_000],
            ['account' => '4000', 'direction' => 'CR', 'amountMinor' => 100_000],
        ];
    }

    /**
     * Slice-1 Minor 1, folded in: `entity_ref` is NOT NULL, but `''` satisfies
     * NOT NULL and no CHECK rejects it.
     *
     * MUTATION RESISTANCE: remove `assertEntityScoped()` from `post()` and this
     * goes red — but the ASSERTION THAT MATTERS is the second one, not the
     * exception. A test that only caught the throw would still pass if someone
     * "fixed" this by defaulting a blank reference to something. The second
     * half proves the consequence the validation exists to prevent: a blank
     * entity_ref makes a batch invisible to every entity-scoped report while
     * still counting toward the unscoped total, so the two views disagree
     * silently and permanently.
     */
    public function test_a_blank_entity_reference_is_refused_because_it_is_scoped_to_nothing(): void
    {
        try {
            $this->journal()->post(
                businessKey: 'payment:blank-entity',
                entityRef: '   ',
                sourceType: 'payment',
                sourceId: 'blank-entity',
                entries: [
                    ['account' => '7000', 'direction' => 'DR', 'amountMinor' => 10_000],
                    ['account' => '4000', 'direction' => 'CR', 'amountMinor' => 10_000],
                ],
            );
            $this->fail('Expected a blank entity reference to be refused.');
        } catch (InvalidJournalBatchException $exception) {
            $this->assertStringContainsString('badan usaha', $exception->getMessage());
        }

        $this->assertSame(0, JournalBatch::query()->count());
        $this->assertSame(0, DB::table('journal_entries')->count());
    }

    private function journal(): Journal
    {
        return $this->app->make(Journal::class);
    }
}
