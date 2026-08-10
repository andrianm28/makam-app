<?php

declare(strict_types=1);

namespace Tests\Feature\FinancialLedger;

use App\Platform\FinancialLedger\Actions\RunReconciliation;
use App\Platform\FinancialLedger\Exceptions\InvalidReconciliationException;
use App\Platform\FinancialLedger\Jobs\ReconcileStatementJob;
use App\Platform\FinancialLedger\Journal;
use App\Platform\FinancialLedger\Models\JournalBatch;
use App\Platform\FinancialLedger\Models\Reconciliation;
use App\Platform\FinancialLedger\Models\ReconciliationException;
use App\Platform\FinancialLedger\ProviderStatement;
use App\Platform\FinancialLedger\ReconciliationExceptionStatus;
use App\Platform\FinancialLedger\ReconciliationExceptionType;
use App\Platform\FinancialLedger\ReconciliationStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AC10 for `Actions\RunReconciliation`.
 *
 * The headline anti-regression test is
 * `test_a_statement_mismatch_becomes_a_finding_and_the_journal_is_never_written`:
 * a mismatch must become a `reconciliation_exceptions` row and NOT an
 * adjustment to the ledger. It asserts the absence of the write itself with a
 * `DB::listen` scan — the technique `JournalReversalTest` established — because
 * a before/after row count would pass even if this code wrote and then rolled
 * back.
 *
 * Every assertion re-reads persisted rows rather than trusting a model instance
 * already in hand. PostgreSQL-only assertions skip explicitly, matching
 * `JournalSchemaTest`'s precedent.
 */
final class RunReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private const string ENTITY = 'badan-usaha-1';

    private const string PERIOD = '2026-07';

    private const int AMOUNT = 250_000;

    public function test_a_statement_mismatch_becomes_a_finding_and_the_journal_is_never_written(): void
    {
        $this->postBatch('payment:evt-1', self::AMOUNT);

        /** @var list<string> $statements */
        $statements = [];
        DB::listen(function ($query) use (&$statements): void {
            $statements[] = $query->sql;
        });

        $reconciliation = $this->reconcile($this->statement(['payment:evt-1' => self::AMOUNT + 1_000]));

        $this->assertNoJournalWrites($statements);

        $exception = ReconciliationException::query()->sole();

        $this->assertSame(ReconciliationExceptionType::AMOUNT_MISMATCH, $exception->type);
        $this->assertSame('payment:evt-1', $exception->subject_ref);
        $this->assertSame(self::PERIOD, $exception->period);
        $this->assertSame(self::ENTITY, $exception->entity_ref);
        $this->assertSame(self::AMOUNT, $exception->journal_amount_minor);
        $this->assertSame(self::AMOUNT + 1_000, $exception->statement_amount_minor);
        $this->assertSame(ReconciliationExceptionStatus::OPEN, $exception->status);
        $this->assertNull($exception->decision);
        $this->assertNull($exception->decided_by);
        $this->assertNull($exception->decided_at);
        $this->assertSame($reconciliation->id, $exception->reconciliation_id);

        // Read back from the database, not from the returned instance.
        $row = DB::table('reconciliations')->where('id', $reconciliation->id)->sole();
        $this->assertSame(ReconciliationStatus::EXCEPTIONS_OPEN, $row->status);
        $this->assertSame(self::AMOUNT, (int) $row->journal_total_minor);
        $this->assertSame(self::AMOUNT + 1_000, (int) $row->statement_total_minor);

        // And the ledger itself is byte-identical.
        $this->assertSame(1, JournalBatch::query()->count());
        $this->assertSame(2, DB::table('journal_entries')->count());
    }

    public function test_a_missing_statement_is_statement_missing_and_never_a_zero_valued_mismatch(): void
    {
        $this->postBatch('payment:evt-1', self::AMOUNT);

        /** @var list<string> $statements */
        $statements = [];
        DB::listen(function ($query) use (&$statements): void {
            $statements[] = $query->sql;
        });

        $reconciliation = $this->reconcile(null);

        $this->assertNoJournalWrites($statements);

        $row = DB::table('reconciliations')->where('id', $reconciliation->id)->sole();

        $this->assertSame(ReconciliationStatus::STATEMENT_MISSING, $row->status);

        // The three assertions that matter. A missing statement is NULL, never
        // `0`; nothing was compared against it; and the period is not presented
        // as reconciled or partially reconciled.
        $this->assertNull($row->statement_total_minor);
        $this->assertNull($row->statement_reference);
        $this->assertSame(0, ReconciliationException::query()->count());
        $this->assertNotSame(ReconciliationStatus::MATCHED, $row->status);
        $this->assertNotSame(ReconciliationStatus::EXCEPTIONS_OPEN, $row->status);

        $reread = Reconciliation::query()->findOrFail($reconciliation->id);
        $this->assertTrue($reread->isStatementMissing());
        $this->assertFalse($reread->hasOpenExceptions());

        // The journal total is still recorded honestly — it is ours and we do
        // know it; only the statement side is unknown.
        $this->assertSame(self::AMOUNT, (int) $row->journal_total_minor);
    }

    public function test_a_statement_line_with_no_journal_batch_is_a_missing_finding_with_a_null_journal_amount(): void
    {
        $reconciliation = $this->reconcile($this->statement(['payment:only-on-statement' => self::AMOUNT]));

        $exception = ReconciliationException::query()->sole();

        $this->assertSame(ReconciliationExceptionType::MISSING, $exception->type);
        $this->assertSame('payment:only-on-statement', $exception->subject_ref);
        $this->assertNull($exception->journal_amount_minor);
        $this->assertSame(self::AMOUNT, $exception->statement_amount_minor);
        $this->assertSame(ReconciliationStatus::EXCEPTIONS_OPEN, $reconciliation->fresh()?->status);
    }

    public function test_a_journal_batch_with_no_statement_line_is_an_extra_finding_with_a_null_statement_amount(): void
    {
        $this->postBatch('payment:evt-1', self::AMOUNT);

        $this->reconcile($this->statement([]));

        $exception = ReconciliationException::query()->sole();

        $this->assertSame(ReconciliationExceptionType::EXTRA, $exception->type);
        $this->assertSame('payment:evt-1', $exception->subject_ref);
        $this->assertSame(self::AMOUNT, $exception->journal_amount_minor);
        $this->assertNull($exception->statement_amount_minor);
    }

    public function test_an_agreeing_period_is_matched_with_no_exceptions(): void
    {
        $this->postBatch('payment:evt-1', self::AMOUNT);
        $this->postBatch('payment:evt-2', 10_000);

        $reconciliation = $this->reconcile($this->statement([
            'payment:evt-1' => self::AMOUNT,
            'payment:evt-2' => 10_000,
        ]));

        $row = DB::table('reconciliations')->where('id', $reconciliation->id)->sole();

        $this->assertSame(ReconciliationStatus::MATCHED, $row->status);
        $this->assertSame(0, ReconciliationException::query()->count());
        $this->assertSame(self::AMOUNT + 10_000, (int) $row->journal_total_minor);
    }

    public function test_an_unbalanced_batch_is_its_own_finding_and_is_not_compared_against_the_statement(): void
    {
        $this->allowUnbalancedWritesForThisTransaction();
        $this->postUnbalancedBatch('payment:evt-broken', debitMinor: self::AMOUNT, creditMinor: 1_000);

        $this->reconcile($this->statement(['payment:evt-broken' => self::AMOUNT]));

        // Exactly ONE finding. The unbalanced batch is excluded from the amount
        // comparison, so it does not also produce a derivative mismatch.
        $exception = ReconciliationException::query()->sole();

        $this->assertSame(ReconciliationExceptionType::UNBALANCED, $exception->type);
        $this->assertSame('payment:evt-broken', $exception->subject_ref);
        $this->assertSame(self::AMOUNT, $exception->journal_amount_minor);
        $this->assertNull($exception->statement_amount_minor);
    }

    public function test_rerunning_the_same_period_does_not_duplicate_exceptions(): void
    {
        $this->postBatch('payment:evt-1', self::AMOUNT);
        $statement = $this->statement(['payment:evt-1' => self::AMOUNT + 1_000]);

        $first = $this->reconcile($statement);
        $firstException = ReconciliationException::query()->sole();

        // The at-least-once redelivery, and then the next scheduled run.
        $second = $this->reconcile($statement);
        $third = $this->reconcile($statement);

        $this->assertSame(1, ReconciliationException::query()->count());
        $this->assertSame(1, Reconciliation::query()->count());
        $this->assertSame($first->id, $second->id);
        $this->assertSame($first->id, $third->id);

        // Not merely "one row" — the SAME row, untouched.
        $reread = ReconciliationException::query()->sole();
        $this->assertSame($firstException->id, $reread->id);
        $this->assertSame($firstException->created_at?->toIso8601String(), $reread->created_at?->toIso8601String());
        $this->assertSame(ReconciliationExceptionStatus::OPEN, $reread->status);
    }

    public function test_the_database_refuses_a_duplicate_finding_even_without_the_application_guard(): void
    {
        $this->postBatch('payment:evt-1', self::AMOUNT);
        $reconciliation = $this->reconcile($this->statement(['payment:evt-1' => self::AMOUNT + 1_000]));

        // `insertOrIgnore` is a read-free write, but the authority for "one
        // finding per difference" is the UNIQUE index — proven here by
        // bypassing the Action entirely.
        $this->expectException(QueryException::class);

        DB::table('reconciliation_exceptions')->insert([
            'id' => (string) Str::uuid(),
            'reconciliation_id' => $reconciliation->id,
            'entity_ref' => self::ENTITY,
            'period' => self::PERIOD,
            'type' => ReconciliationExceptionType::AMOUNT_MISMATCH,
            'subject_ref' => 'payment:evt-1',
            'journal_amount_minor' => self::AMOUNT,
            'statement_amount_minor' => self::AMOUNT + 1_000,
            'status' => ReconciliationExceptionStatus::OPEN,
            'decision' => null,
            'decided_by' => null,
            'decided_at' => null,
            'correlation_id' => null,
            'created_at' => CarbonImmutable::now(),
            'updated_at' => CarbonImmutable::now(),
        ]);
    }

    public function test_the_database_refuses_a_second_reconciliation_for_the_same_entity_and_period(): void
    {
        $this->reconcile($this->statement([]));

        $this->expectException(QueryException::class);

        DB::table('reconciliations')->insert([
            'id' => (string) Str::uuid(),
            'entity_ref' => self::ENTITY,
            'period' => self::PERIOD,
            'status' => ReconciliationStatus::MATCHED,
            'statement_reference' => 'statement-ref-2',
            'statement_total_minor' => 0,
            'journal_total_minor' => 0,
            'correlation_id' => null,
            'ran_at' => CarbonImmutable::now(),
            'created_at' => CarbonImmutable::now(),
            'updated_at' => CarbonImmutable::now(),
        ]);
    }

    public function test_only_batches_inside_the_period_window_are_compared(): void
    {
        $this->postBatch('payment:in-window', self::AMOUNT, at: '2026-07-31 23:59:59');
        $this->postBatch('payment:next-month', 99_000, at: '2026-08-01 00:00:00');
        $this->postBatch('payment:previous-month', 88_000, at: '2026-06-30 23:59:59');
        $this->postBatch('payment:other-entity', 77_000, entityRef: 'badan-usaha-2');

        $reconciliation = $this->reconcile($this->statement(['payment:in-window' => self::AMOUNT]));

        $this->assertSame(ReconciliationStatus::MATCHED, $reconciliation->fresh()?->status);
        $this->assertSame(0, ReconciliationException::query()->count());
        $this->assertSame(self::AMOUNT, (int) DB::table('reconciliations')
            ->where('id', $reconciliation->id)->value('journal_total_minor'));
    }

    public function test_a_statement_for_another_period_or_entity_is_refused_before_anything_is_written(): void
    {
        foreach ([
            new ProviderStatement('statement-ref-1', '2026-06', self::ENTITY, []),
            new ProviderStatement('statement-ref-1', self::PERIOD, 'badan-usaha-2', []),
        ] as $statement) {
            try {
                $this->reconcile($statement);
                $this->fail('Expected a mismatched statement to be refused.');
            } catch (InvalidReconciliationException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame(0, Reconciliation::query()->count());
        $this->assertSame(0, ReconciliationException::query()->count());
    }

    public function test_a_malformed_period_is_refused_before_anything_is_written(): void
    {
        foreach (['2026-7', '2026-13', 'July 2026', '2026'] as $period) {
            try {
                $this->app->make(RunReconciliation::class)->run($period, self::ENTITY, null);
                $this->fail("Expected period [{$period}] to be refused.");
            } catch (InvalidReconciliationException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame(0, Reconciliation::query()->count());
    }

    public function test_a_statement_line_amount_must_be_integer_minor_units(): void
    {
        $this->expectException(InvalidReconciliationException::class);

        // Deliberately weak input: AC11 rejects a decimal string rather than
        // truncating it, the same way `Money`'s own constructor does.
        new ProviderStatement('statement-ref-1', self::PERIOD, self::ENTITY, ['payment:evt-1' => '250000']);
    }

    public function test_the_reconcile_statement_job_is_queued_on_reports_and_not_on_a_priority_queue(): void
    {
        Queue::fake();

        ReconcileStatementJob::dispatch(self::PERIOD, self::ENTITY, $this->statement([]));

        Queue::assertPushedOn('reports', ReconcileStatementJob::class);
        $this->assertSame('reports', ReconcileStatementJob::queueName());
        $this->assertNotContains(
            ReconcileStatementJob::queueName(),
            ['critical', 'urgent', 'notifications'],
        );
    }

    public function test_the_reconcile_statement_job_runs_the_reconciliation(): void
    {
        $this->postBatch('payment:evt-1', self::AMOUNT);

        $this->app->call([
            new ReconcileStatementJob(self::PERIOD, self::ENTITY, $this->statement([
                'payment:evt-1' => self::AMOUNT + 5,
            ])),
            'handle',
        ]);

        $this->assertSame(1, Reconciliation::query()->count());
        $this->assertSame(
            ReconciliationExceptionType::AMOUNT_MISMATCH,
            ReconciliationException::query()->sole()->type,
        );
    }

    public function test_the_database_refuses_a_statement_missing_row_that_carries_a_statement_total(): void
    {
        $this->skipUnlessPostgres('The statement-missing CHECK constraints are PostgreSQL-only.');

        $this->expectException(QueryException::class);

        // "A missing statement is not a zero", at the database. A row cannot
        // claim the statement was unavailable while also reporting its total.
        DB::table('reconciliations')->insert([
            'id' => (string) Str::uuid(),
            'entity_ref' => self::ENTITY,
            'period' => self::PERIOD,
            'status' => ReconciliationStatus::STATEMENT_MISSING,
            'statement_reference' => null,
            'statement_total_minor' => 0,
            'journal_total_minor' => 0,
            'correlation_id' => null,
            'ran_at' => CarbonImmutable::now(),
            'created_at' => CarbonImmutable::now(),
            'updated_at' => CarbonImmutable::now(),
        ]);
    }

    public function test_the_database_refuses_a_matched_row_with_no_statement_total(): void
    {
        $this->skipUnlessPostgres('The statement-missing CHECK constraints are PostgreSQL-only.');

        $this->expectException(QueryException::class);

        DB::table('reconciliations')->insert([
            'id' => (string) Str::uuid(),
            'entity_ref' => self::ENTITY,
            'period' => self::PERIOD,
            'status' => ReconciliationStatus::MATCHED,
            'statement_reference' => 'statement-ref-1',
            'statement_total_minor' => null,
            'journal_total_minor' => 0,
            'correlation_id' => null,
            'ran_at' => CarbonImmutable::now(),
            'created_at' => CarbonImmutable::now(),
            'updated_at' => CarbonImmutable::now(),
        ]);
    }

    public function test_the_database_refuses_an_amount_mismatch_whose_two_amounts_agree(): void
    {
        $this->skipUnlessPostgres('The per-type shape CHECK constraints are PostgreSQL-only.');

        $reconciliation = $this->reconcile($this->statement([]));

        $this->expectException(QueryException::class);

        // A recorded mismatch that is not a mismatch is a false finding.
        DB::table('reconciliation_exceptions')->insert($this->exceptionAttributes($reconciliation, [
            'type' => ReconciliationExceptionType::AMOUNT_MISMATCH,
            'journal_amount_minor' => self::AMOUNT,
            'statement_amount_minor' => self::AMOUNT,
        ]));
    }

    public function test_the_database_refuses_a_missing_finding_that_claims_a_zero_journal_amount(): void
    {
        $this->skipUnlessPostgres('The per-type shape CHECK constraints are PostgreSQL-only.');

        $reconciliation = $this->reconcile($this->statement([]));

        $this->expectException(QueryException::class);

        // The whole ruling in one constraint: the journal did not report zero
        // for this line, it reported nothing.
        DB::table('reconciliation_exceptions')->insert($this->exceptionAttributes($reconciliation, [
            'type' => ReconciliationExceptionType::MISSING,
            'journal_amount_minor' => 0,
            'statement_amount_minor' => self::AMOUNT,
        ]));
    }

    public function test_the_database_refuses_an_unknown_exception_type(): void
    {
        $this->skipUnlessPostgres('The closed-list CHECK constraints are PostgreSQL-only.');

        $reconciliation = $this->reconcile($this->statement([]));

        $this->expectException(QueryException::class);

        DB::table('reconciliation_exceptions')->insert($this->exceptionAttributes($reconciliation, [
            'type' => 'auto_corrected',
        ]));
    }

    public function test_the_database_refuses_a_resolved_exception_with_no_decider(): void
    {
        $this->skipUnlessPostgres('The AC12 decision CHECK constraints are PostgreSQL-only.');

        $reconciliation = $this->reconcile($this->statement([]));

        $this->expectException(QueryException::class);

        DB::table('reconciliation_exceptions')->insert($this->exceptionAttributes($reconciliation, [
            'status' => ReconciliationExceptionStatus::RESOLVED,
            'decision' => null,
            'decided_by' => null,
            'decided_at' => null,
        ]));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function exceptionAttributes(Reconciliation $reconciliation, array $overrides = []): array
    {
        return array_merge([
            'id' => (string) Str::uuid(),
            'reconciliation_id' => $reconciliation->id,
            'entity_ref' => self::ENTITY,
            'period' => self::PERIOD,
            'type' => ReconciliationExceptionType::EXTRA,
            'subject_ref' => 'payment:direct-insert',
            'journal_amount_minor' => self::AMOUNT,
            'statement_amount_minor' => null,
            'status' => ReconciliationExceptionStatus::OPEN,
            'decision' => null,
            'decided_by' => null,
            'decided_at' => null,
            'correlation_id' => null,
            'created_at' => CarbonImmutable::now(),
            'updated_at' => CarbonImmutable::now(),
        ], $overrides);
    }

    /**
     * @param  list<string>  $statements
     */
    private function assertNoJournalWrites(array $statements): void
    {
        $writes = array_values(array_filter(
            $statements,
            fn (string $sql): bool => preg_match('/^\s*(insert|update|delete)\b/i', $sql) === 1
                && preg_match('/\bjournal_(batches|entries)\b/i', $sql) === 1
        ));

        // AC10's negative criterion. Asserted on the STATEMENTS, not on a
        // before/after row count, because a count would pass even if this code
        // wrote to the ledger and then rolled back.
        $this->assertSame([], $writes);
    }

    /**
     * @param  array<string, int>  $lines
     */
    private function statement(array $lines): ProviderStatement
    {
        return new ProviderStatement(
            reference: 'statement-ref-1',
            period: self::PERIOD,
            entityRef: self::ENTITY,
            lines: $lines,
        );
    }

    private function reconcile(?ProviderStatement $statement): Reconciliation
    {
        return $this->app->make(RunReconciliation::class)->run(
            period: self::PERIOD,
            entityRef: self::ENTITY,
            statement: $statement,
        );
    }

    private function postBatch(
        string $businessKey,
        int $amountMinor,
        string $at = '2026-07-15 10:00:00',
        string $entityRef = self::ENTITY,
    ): JournalBatch {
        return $this->app->make(Journal::class)->post(
            businessKey: $businessKey,
            entityRef: $entityRef,
            sourceType: 'payment',
            sourceId: $businessKey,
            entries: [
                ['account' => '7000', 'direction' => 'DR', 'amountMinor' => $amountMinor],
                ['account' => '4000', 'direction' => 'CR', 'amountMinor' => $amountMinor],
            ],
            occurredAt: $at,
        );
    }

    /**
     * Write a deliberately unbalanced batch by hand. `Journal` cannot produce
     * one and PostgreSQL's `assert_balanced_batch` trigger rejects one — which
     * is exactly why reconciliation still looks for it: a detective control
     * that trusts the preventive control has stopped detecting. See
     * `allowUnbalancedWritesForThisTransaction()`.
     */
    private function postUnbalancedBatch(string $businessKey, int $debitMinor, int $creditMinor): void
    {
        $batchId = (string) Str::uuid();

        DB::table('journal_batches')->insert([
            'id' => $batchId,
            'business_key' => $businessKey,
            'entity_ref' => self::ENTITY,
            'source_type' => 'payment',
            'source_id' => $businessKey,
            'correlation_id' => null,
            'occurred_at' => '2026-07-15 10:00:00',
            'status' => 'posted',
            'reverses_batch_id' => null,
        ]);

        DB::table('journal_entries')->insert([
            [
                'batch_id' => $batchId,
                'account_code' => '7000',
                'direction' => 'DR',
                'amount_minor' => $debitMinor,
                'currency' => 'IDR',
                'reference' => null,
            ],
            [
                'batch_id' => $batchId,
                'account_code' => '4000',
                'direction' => 'CR',
                'amount_minor' => $creditMinor,
                'currency' => 'IDR',
                'reference' => null,
            ],
        ]);
    }

    /**
     * `assert_balanced_batch` is `DEFERRABLE INITIALLY IMMEDIATE`, so deferring
     * it lets this transaction hold an unbalanced batch for the duration of the
     * test. The check would fire at COMMIT — which never happens, because
     * `RefreshDatabase` rolls the test back. No-op on SQLite, which has no such
     * trigger.
     */
    private function allowUnbalancedWritesForThisTransaction(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('SET CONSTRAINTS assert_balanced_batch DEFERRED');
        }
    }

    private function skipUnlessPostgres(string $message): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped($message.' Run with DB_CONNECTION=pgsql.');
        }
    }
}
