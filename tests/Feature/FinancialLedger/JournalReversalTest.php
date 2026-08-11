<?php

declare(strict_types=1);

namespace Tests\Feature\FinancialLedger;

use App\Platform\Audit\AuditOutcome;
use App\Platform\Audit\Models\AuditEvent;
use App\Platform\Audit\SensitiveActions;
use App\Platform\FinancialLedger\Exceptions\InvalidJournalBatchException;
use App\Platform\FinancialLedger\Exceptions\JournalBatchAlreadyReversedException;
use App\Platform\FinancialLedger\Exceptions\UnknownJournalBatchException;
use App\Platform\FinancialLedger\Journal;
use App\Platform\FinancialLedger\JournalReversalKind;
use App\Platform\FinancialLedger\LedgerReport;
use App\Platform\FinancialLedger\Models\JournalBatch;
use App\Platform\FinancialLedger\Models\JournalEntry;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AC2 and AC14 for `Journal::postReversal()`: a correction is a NEW batch,
 * never an edit. Two user-approved Wave 1b rulings shape these tests —
 * reversed-ness is derived from the existence of a reversing batch (never a
 * stored status), and the reversal -> original link is a real
 * `reverses_batch_id` foreign key (never a business key in a free-text note).
 *
 * PostgreSQL-only assertions skip explicitly, matching
 * `tests/Feature/FinancialLedger/JournalSchemaTest.php`'s precedent.
 */
final class JournalReversalTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_reversal_flips_every_entry_and_links_to_the_original(): void
    {
        $original = $this->postOriginal();

        $reversal = $this->journal()->postReversal(
            originalBusinessKey: 'payment:original-event',
            reason: 'Provider settlement retracted by bank',
        );

        $this->assertSame('reversal:payment:original-event', $reversal->business_key);
        $this->assertSame('reversal', $reversal->source_type);
        $this->assertSame($original->id, $reversal->source_id);
        $this->assertSame($original->entity_ref, $reversal->entity_ref);
        $this->assertSame('posted', $reversal->status);
        $this->assertSame($original->id, $reversal->reverses_batch_id);

        $originalEntries = $this->entryTuples($original->id);
        $reversalEntries = $this->entryTuples($reversal->id);

        $this->assertSame(
            [['4000', 'DR', 250_000], ['7000', 'CR', 250_000]],
            $reversalEntries
        );
        $this->assertSame(
            [['4000', 'CR', 250_000], ['7000', 'DR', 250_000]],
            $originalEntries
        );

        // Original + reversal net to zero on every account — the ledger
        // reads both batches, it never rewrites one.
        $net = DB::table('journal_entries')
            ->selectRaw("COALESCE(SUM(CASE WHEN direction = 'DR' THEN amount_minor ELSE -amount_minor END), 0) AS balance")
            ->value('balance');
        $this->assertSame(0, (int) $net);
        $this->assertTrue($reversal->isBalanced());
    }

    public function test_every_reversal_writes_a_sensitive_audit_event_with_its_reason(): void
    {
        $this->postOriginal();

        $reversal = $this->journal()->postReversal(
            originalBusinessKey: 'payment:original-event',
            reason: 'Provider settlement retracted by bank',
        );

        $event = AuditEvent::query()
            ->where('action', 'JOURNAL_REVERSAL')
            ->where('subject_id', $reversal->id)
            ->sole();

        $this->assertSame(AuditOutcome::Allowed->value, $event->outcome);
        $this->assertSame('journal_batch', $event->subject_type);
        $this->assertSame('Provider settlement retracted by bank', $event->reason);
        $this->assertTrue(SensitiveActions::requiresReason('JOURNAL_REVERSAL'));
    }

    public function test_reversal_batch_and_audit_roll_back_together_with_the_callers_transaction(): void
    {
        $original = $this->postOriginal();

        try {
            DB::transaction(function (): void {
                $this->journal()->postReversal(
                    originalBusinessKey: 'payment:original-event',
                    reason: 'Provider settlement retracted by bank',
                );

                throw new \RuntimeException('rollback reversal fixture');
            });
            $this->fail('Expected the caller transaction to roll back.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('rollback reversal fixture', $exception->getMessage());
        }

        $this->assertSame(1, JournalBatch::query()->count());
        $this->assertFalse(JournalBatch::query()->findOrFail($original->id)->isReversed());
        $this->assertSame(0, AuditEvent::query()->where('action', 'JOURNAL_REVERSAL')->count());
    }

    public function test_reversal_batch_and_audit_are_atomic_inside_the_journal_api(): void
    {
        $this->postOriginal();
        $auditFailed = false;

        DB::listen(function ($query) use (&$auditFailed): void {
            if ($auditFailed || ! str_contains(strtolower($query->sql), 'audit_events')) {
                return;
            }

            $auditFailed = true;
            throw new \RuntimeException('audit fixture failure');
        });

        try {
            $this->journal()->postReversal(
                originalBusinessKey: 'payment:original-event',
                reason: 'Provider settlement retracted by bank',
            );
            $this->fail('Expected the audit fixture failure.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('audit fixture failure', $exception->getMessage());
        }

        $this->assertTrue($auditFailed);
        $this->assertSame(1, JournalBatch::query()->count());
        $this->assertSame(0, AuditEvent::query()->where('action', 'JOURNAL_REVERSAL')->count());
        $this->assertFalse(JournalBatch::query()->firstOrFail()->isReversed());
    }

    public function test_the_reason_is_written_to_the_reversing_entries_own_note_column(): void
    {
        $this->postOriginal();

        $reversal = $this->journal()->postReversal(
            originalBusinessKey: 'payment:original-event',
            reason: 'Provider settlement retracted by bank',
        );

        $references = JournalEntry::query()->where('batch_id', $reversal->id)->pluck('reference')->all();

        $this->assertSame(
            ['Provider settlement retracted by bank', 'Provider settlement retracted by bank'],
            $references
        );
    }

    public function test_a_refund_reversal_uses_the_refund_prefix_and_source_type(): void
    {
        $this->postOriginal();

        $refund = $this->journal()->postReversal(
            originalBusinessKey: 'payment:original-event',
            reason: 'Customer cancelled before the service date',
            kind: JournalReversalKind::Refund,
        );

        $this->assertSame('refund:payment:original-event', $refund->business_key);
        $this->assertSame('refund', $refund->source_type);
    }

    public function test_the_original_batch_and_entries_are_byte_identical_after_a_reversal(): void
    {
        $original = $this->postOriginal();

        $batchBefore = (array) DB::table('journal_batches')->where('id', $original->id)->sole();
        $entriesBefore = DB::table('journal_entries')
            ->where('batch_id', $original->id)->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all();

        $this->journal()->postReversal(
            originalBusinessKey: 'payment:original-event',
            reason: 'Provider settlement retracted by bank',
        );

        $batchAfter = (array) DB::table('journal_batches')->where('id', $original->id)->sole();
        $entriesAfter = DB::table('journal_entries')
            ->where('batch_id', $original->id)->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all();

        // Asserted on the persisted rows, not on in-memory model objects.
        $this->assertSame($batchBefore, $batchAfter);
        $this->assertSame($entriesBefore, $entriesAfter);
        $this->assertNull($batchAfter['reverses_batch_id']);
        $this->assertSame('posted', $batchAfter['status']);
    }

    public function test_reversed_ness_is_derived_with_no_update_statement_on_journal_batches(): void
    {
        $original = $this->postOriginal();

        $this->assertFalse($original->isReversed());
        $this->assertNull($original->reversedBy);

        /** @var list<string> $statements */
        $statements = [];
        DB::listen(function ($query) use (&$statements): void {
            $statements[] = $query->sql;
        });

        $reversal = $this->journal()->postReversal(
            originalBusinessKey: 'payment:original-event',
            reason: 'Provider settlement retracted by bank',
        );

        $mutations = array_values(array_filter(
            $statements,
            fn (string $sql): bool => preg_match('/^\s*(update|delete)\b/i', $sql) === 1
        ));

        // Ruling 1: reversed-ness is DERIVED. Proving the end state alone
        // would not distinguish "derived" from "a status column was written",
        // so the absence of the write itself is what is asserted here.
        $this->assertSame([], $mutations);

        $refreshed = JournalBatch::query()->findOrFail($original->id);

        $this->assertTrue($refreshed->isReversed());
        $this->assertSame($reversal->id, $refreshed->reversedBy?->id);
        $this->assertSame($original->id, $reversal->reverses?->id);
        $this->assertFalse($reversal->isReversed());
    }

    public function test_reversing_an_unknown_business_key_fails_loudly(): void
    {
        $this->expectException(UnknownJournalBatchException::class);
        $this->expectExceptionMessageMatches('/payment:never-posted/');

        $this->journal()->postReversal(
            originalBusinessKey: 'payment:never-posted',
            reason: 'Typo in the business key',
        );
    }

    public function test_a_reversal_requires_a_reason(): void
    {
        $this->postOriginal();

        try {
            $this->journal()->postReversal(
                originalBusinessKey: 'payment:original-event',
                reason: '   ',
            );
            $this->fail('Expected a blank reversal reason to be rejected.');
        } catch (InvalidJournalBatchException $exception) {
            $this->assertStringContainsString('reason', $exception->getMessage());
        }

        $this->assertSame(1, JournalBatch::query()->count());
        $this->assertSame(0, AuditEvent::query()->where('action', 'JOURNAL_REVERSAL')->count());
    }

    public function test_a_batch_cannot_be_reversed_twice(): void
    {
        $this->postOriginal();

        $this->journal()->postReversal(
            originalBusinessKey: 'payment:original-event',
            reason: 'Provider settlement retracted by bank',
        );

        try {
            // A different KIND, so the business_key UNIQUE constraint alone
            // would not have caught this one — `refund:payment:original-event`
            // does not collide with `reversal:payment:original-event`.
            $this->journal()->postReversal(
                originalBusinessKey: 'payment:original-event',
                reason: 'Second correction attempt',
                kind: JournalReversalKind::Refund,
            );
            $this->fail('Expected JournalBatchAlreadyReversedException.');
        } catch (JournalBatchAlreadyReversedException $exception) {
            $this->assertStringContainsString('payment:original-event', $exception->getMessage());
        }

        $this->assertSame(2, JournalBatch::query()->count());
        $this->assertSame(4, JournalEntry::query()->count());
    }

    public function test_the_database_rejects_a_second_reversing_row_even_without_the_application_guard(): void
    {
        $original = $this->postOriginal();

        $this->journal()->postReversal(
            originalBusinessKey: 'payment:original-event',
            reason: 'Provider settlement retracted by bank',
        );

        // The application guard is a read-then-insert check and therefore not
        // race-proof on its own. The UNIQUE index on `reverses_batch_id` is
        // the authority, exactly as the balance trigger is the authority for
        // AC1 — proven here by bypassing Journal entirely.
        $this->expectException(QueryException::class);

        DB::table('journal_batches')->insert([
            'id' => (string) Str::uuid(),
            'business_key' => 'refund:payment:original-event',
            'entity_ref' => $original->entity_ref,
            'source_type' => 'refund',
            'source_id' => $original->id,
            'correlation_id' => null,
            'occurred_at' => now(),
            'status' => 'posted',
            'reverses_batch_id' => $original->id,
        ]);
    }

    public function test_a_batch_cannot_reverse_itself(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped(
                'The journal_batches_no_self_reversal_check CHECK is PostgreSQL-only; run this test with DB_CONNECTION=pgsql.'
            );
        }

        $id = (string) Str::uuid();

        $this->expectException(QueryException::class);

        DB::table('journal_batches')->insert([
            'id' => $id,
            'business_key' => 'reversal:payment:self',
            'entity_ref' => 'badan-usaha-1',
            'source_type' => 'reversal',
            'source_id' => $id,
            'correlation_id' => null,
            'occurred_at' => now(),
            'status' => 'posted',
            'reverses_batch_id' => $id,
        ]);
    }

    public function test_reverses_batch_id_must_reference_a_real_batch(): void
    {
        // Not PostgreSQL-only: verified by execution that Laravel 13's SQLite
        // grammar realises this ALTER-added foreign key too (rebuilding the
        // table), and that `PRAGMA foreign_keys` is on, so the link is
        // enforced on both drivers rather than only in production.
        $this->expectException(QueryException::class);

        DB::table('journal_batches')->insert([
            'id' => (string) Str::uuid(),
            'business_key' => 'reversal:payment:ghost',
            'entity_ref' => 'badan-usaha-1',
            'source_type' => 'reversal',
            'source_id' => 'ghost',
            'correlation_id' => null,
            'occurred_at' => now(),
            'status' => 'posted',
            'reverses_batch_id' => (string) Str::uuid(),
        ]);
    }

    /**
     * @return list<array{0: string, 1: string, 2: int}>
     */
    /**
     * AC12's reproducibility, at the one place it can be silently broken.
     *
     * `Journal::postReversal()` documents in prose that "a reversal is its own
     * economic event at its own time. The original's `occurred_at` is
     * deliberately not copied — doing so would backdate the correction into a
     * period that has already been reported on." Until this test, nothing
     * asserted it: an independent reviewer changed `Journal.php:230` to
     * `'occurred_at' => $original->occurred_at` and the ENTIRE suite stayed
     * green on both drivers.
     *
     * ---------------------------------------------------------------------
     * Why this asserts through `LedgerReport` and not only on the column
     * ---------------------------------------------------------------------
     * The consequence that matters is not the column value, it is that
     * `LedgerReport::summary()` filters the period on
     * `journal_batches.occurred_at`. Under the mutation, reversing a July batch
     * in August files the correction into JULY — a period already reported and
     * signed off — so `summary('2026-07')` silently returns a different number
     * the next time it is generated. Asserting the report totals pins the
     * property at the level AC12 states it.
     *
     * The shape is deliberately borrowed from
     * `LedgerReportTest::test_period_and_entity_filters_exclude_out_of_scope_rows`,
     * which is what already protects `post()`'s own `occurredAt` argument, so
     * the two halves of the same property are protected the same way.
     *
     * MUTATION RESISTANCE, reasoned explicitly rather than assumed: under
     * `'occurred_at' => $original->occurred_at` the reversal's DR 4000 /
     * CR 7000 land in 2026-07 instead of 2026-08. July's debit total moves from
     * 250_000 to 500_000 and August's from 250_000 to 0. Four of the
     * assertions below change value, not merely one, and the raw-column
     * assertion changes too. Verified red by running exactly that mutation.
     */
    public function test_a_reversal_lands_in_its_own_period_and_is_never_backdated_into_the_originals(): void
    {
        $this->journal()->post(
            businessKey: 'payment:july-event',
            entityRef: 'badan-usaha-1',
            sourceType: 'payment',
            sourceId: 'july-event',
            entries: [
                ['account' => '7000', 'direction' => 'DR', 'amountMinor' => 250_000],
                ['account' => '4000', 'direction' => 'CR', 'amountMinor' => 250_000],
            ],
            occurredAt: '2026-07-15T09:00:00',
        );

        $reversal = $this->journal()->postReversal(
            originalBusinessKey: 'payment:july-event',
            reason: 'Provider settlement retracted after the July books closed',
            occurredAt: '2026-08-03T09:00:00',
        );

        // The column itself: the reversal carries its own time, not the
        // original's.
        $this->assertSame(
            '2026-08-03 09:00:00',
            (string) DB::table('journal_batches')->where('id', $reversal->id)->value('occurred_at'),
        );

        $july = app(LedgerReport::class)->summary('2026-07');
        $august = app(LedgerReport::class)->summary('2026-08');

        // July still reads exactly as it did when it was reported: the original
        // batch and nothing else. A backdated reversal would double it.
        $this->assertSame(250_000, array_sum(array_column($july->rows, 'debit_total')));
        $this->assertSame(250_000, array_sum(array_column($july->rows, 'credit_total')));

        // August carries the correction, in the period it actually happened in.
        $this->assertSame(250_000, array_sum(array_column($august->rows, 'debit_total')));
        $this->assertSame(250_000, array_sum(array_column($august->rows, 'credit_total')));

        // And the two periods net to zero together — the reversal cancels the
        // original across the ledger as a whole, which is the point of posting
        // it forward rather than editing backwards.
        $this->assertSame(
            0,
            array_sum(array_column($july->rows, 'net')) + array_sum(array_column($august->rows, 'net')),
        );
    }

    /**
     * The default path — no explicit `occurredAt` — must also land in the
     * present, not in the original's period. This is the shape a real
     * correction takes (a human reverses a batch today), and it is the one the
     * mutation above would break most quietly.
     */
    public function test_a_reversal_with_no_explicit_time_lands_in_the_current_period(): void
    {
        $this->travelTo('2026-07-15 09:00:00');

        $this->journal()->post(
            businessKey: 'payment:travelled-event',
            entityRef: 'badan-usaha-1',
            sourceType: 'payment',
            sourceId: 'travelled-event',
            entries: [
                ['account' => '7000', 'direction' => 'DR', 'amountMinor' => 100_000],
                ['account' => '4000', 'direction' => 'CR', 'amountMinor' => 100_000],
            ],
        );

        $this->travelTo('2026-08-03 09:00:00');

        $reversal = $this->journal()->postReversal(
            originalBusinessKey: 'payment:travelled-event',
            reason: 'Corrected in the month it was discovered',
        );

        $this->assertSame('2026-08', $reversal->occurred_at->format('Y-m'));
        $this->assertSame(
            100_000,
            array_sum(array_column(
                app(LedgerReport::class)->summary('2026-07')->rows,
                'debit_total',
            )),
        );
        $this->assertSame(
            100_000,
            array_sum(array_column(
                app(LedgerReport::class)->summary('2026-08')->rows,
                'debit_total',
            )),
        );
    }

    private function entryTuples(string $batchId): array
    {
        return JournalEntry::query()
            ->where('batch_id', $batchId)
            ->orderBy('account_code')
            ->get()
            ->map(fn (JournalEntry $entry): array => [
                $entry->account_code,
                $entry->direction,
                $entry->amount_minor,
            ])
            ->all();
    }

    private function postOriginal(): JournalBatch
    {
        return $this->journal()->post(
            businessKey: 'payment:original-event',
            entityRef: 'badan-usaha-1',
            sourceType: 'payment',
            sourceId: 'original-event',
            entries: [
                ['account' => '7000', 'direction' => 'DR', 'amountMinor' => 250_000],
                ['account' => '4000', 'direction' => 'CR', 'amountMinor' => 250_000],
            ],
        );
    }

    private function journal(): Journal
    {
        return $this->app->make(Journal::class);
    }
}
