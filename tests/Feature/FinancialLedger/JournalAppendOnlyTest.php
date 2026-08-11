<?php

declare(strict_types=1);

namespace Tests\Feature\FinancialLedger;

use App\Platform\FinancialLedger\Journal;
use App\Platform\FinancialLedger\Models\JournalBatch;
use App\Platform\FinancialLedger\Models\JournalEntry;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * AC2's "never edit or delete history" at the application layer, mirroring
 * `tests/Feature/Audit/AuditEventAppendOnlyTest.php`'s discipline.
 *
 * There is an honest difference from the Audit twin this test is named
 * after: `AuditEvent` OVERRIDES `update()`/`delete()` to throw
 * (`AuditRecordIsImmutableException`), so that test can prove a model-level
 * refusal. `JournalBatch`/`JournalEntry` carry no such override — they are
 * `$guarded = ['*']` and read-only BY CONVENTION (nothing in this module
 * mutates them; Task 3 verified zero `->update()`/`->save()`/`->insert()`
 * calls outside the write API, and this test locks that in), not immutable
 * BY OVERRIDE. A direct `->update()` or `->save()` on a hydrated batch
 * therefore passes the MODEL layer unchallenged, and this test says so out
 * loud rather than pretending the model guard is the control.
 *
 * ---------------------------------------------------------------------------
 * What changed at Task 9b, and what did not
 * ---------------------------------------------------------------------------
 * The real control is now the `journal_batches_append_only` /
 * `journal_entries_append_only` triggers added by migration
 * `2026_08_11_100000`, which refuse every `UPDATE` and `DELETE` on both tables
 * for every role. The two bypass tests below therefore no longer assert that
 * the bypass SUCCEEDS on PostgreSQL — they assert that it is REFUSED, by the
 * database, with the deliberate policy message. This is the "revisit rather
 * than delete quietly" this doc block previously called for, arriving from the
 * trigger rather than from the `REVOKE`.
 *
 * Two things deliberately did NOT change:
 *
 *  - `sql/revoke-journal-mutations.sql` is still documented as NOT EXECUTED and
 *    is still blocked on finding N-1. Sign-off bundle item 14 is NOT closed by
 *    the trigger. The two controls are complements: a `REVOKE` cannot constrain
 *    the owning role, and a trigger can be disabled by a superuser or dropped
 *    by the owner.
 *  - On SQLite there is no trigger, because SQLite has no PL/pgSQL. The
 *    model-layer gap is therefore still fully open there, and the tests below
 *    assert exactly that on that driver rather than skipping. A green SQLite
 *    run is not evidence that AC2 is enforced anywhere.
 */
final class JournalAppendOnlyTest extends TestCase
{
    use RefreshDatabase;

    public function test_post_and_post_reversal_emit_only_insert_and_select_statements_never_update_or_delete(): void
    {
        $this->journal()->post(
            businessKey: 'payment:provider-event-app-1',
            entityRef: 'badan-usaha-1',
            sourceType: 'payment',
            sourceId: 'provider-event-app-1',
            entries: [
                ['account' => '7000', 'direction' => 'DR', 'amountMinor' => 100_000],
                ['account' => '4000', 'direction' => 'CR', 'amountMinor' => 100_000],
            ],
            correlationId: 'trace-append-only-1',
            occurredAt: '2026-08-10T09:00:00+07:00',
        );

        $statements = [];
        DB::listen(function (QueryExecuted $query) use (&$statements): void {
            $statements[] = $query->sql;
        });

        $this->journal()->post(
            businessKey: 'payment:provider-event-app-2',
            entityRef: 'badan-usaha-1',
            sourceType: 'payment',
            sourceId: 'provider-event-app-2',
            entries: [
                ['account' => '7000', 'direction' => 'DR', 'amountMinor' => 50_000],
                ['account' => '4000', 'direction' => 'CR', 'amountMinor' => 50_000],
            ],
            correlationId: 'trace-append-only-2',
            occurredAt: '2026-08-10T09:05:00+07:00',
        );

        $this->journal()->postReversal(
            originalBusinessKey: 'payment:provider-event-app-1',
            reason: 'Wrong amount — replaced by corrected batch',
            correlationId: 'trace-append-only-3',
            occurredAt: '2026-08-10T09:10:00+07:00',
        );

        // `assertGreaterThan(0, $statements)` was the original guard and was
        // vacuous: `[] > 0` is `true` in PHP, so an empty capture passed and
        // the `foreach` below became a no-op that asserted nothing. Anyone who
        // later moved the `DB::listen()` registration, switched `Journal` to a
        // connection the listener is not attached to, or wrapped the writes so
        // no `QueryExecuted` fires would have turned this — the lane's central
        // AC2 test — into a permanent silent green.
        $this->assertNotEmpty($statements, 'The write path must actually touch the database.');
        $this->assertNotEmpty(
            array_filter($statements, static fn (string $sql): bool => stripos($sql, 'insert') !== false),
            'The write path must emit at least one INSERT — a capture of only SELECTs means the '
            .'listener saw a different connection than the one the journal wrote on.',
        );

        foreach ($statements as $sql) {
            $this->assertDoesNotMatchRegularExpression(
                '/\b(update|delete|replace|upsert)\b/i',
                $sql,
                "The journal write path emitted a mutation statement: [{$sql}]"
            );
        }
    }

    public function test_the_journal_models_carry_no_mutation_methods_of_their_own(): void
    {
        $this->assertTrue(property_exists(JournalBatch::class, 'guarded'));
        $this->assertSame(['*'], (new \ReflectionClass(JournalBatch::class))->getDefaultProperties()['guarded'] ?? []);

        $this->assertFalse(method_exists(JournalBatch::class, 'persist'), 'A persisted mutation helper must never appear.');
        $this->assertFalse(method_exists(JournalEntry::class, 'persist'), 'A persisted mutation helper must never appear.');
    }

    /**
     * `JournalBatch::query()->update()` goes through
     * `Illuminate\Database\Eloquent\Builder`, not a model override, so no
     * model-level guard intercepts it — exactly the same reason the Audit
     * twin's `test_query_builder_mass_update_bypasses_the_model_level_guard...`
     * exists. Until Task 9b there was nothing behind the model layer and this
     * test asserted the bypass SUCCEEDED, as the visible marker of that gap.
     *
     * The `journal_batches_append_only` trigger is now what stands behind it,
     * so on PostgreSQL the bypass is refused and the row is proved unchanged.
     * On SQLite there is no trigger and the gap is still fully open — asserted,
     * not skipped, so nobody reads a green SQLite run as enforcement.
     */
    public function test_the_database_refuses_a_query_builder_mass_update_on_journal_batches(): void
    {
        $batch = $this->journal()->post(
            businessKey: 'payment:provider-event-app-3',
            entityRef: 'badan-usaha-1',
            sourceType: 'payment',
            sourceId: 'provider-event-app-3',
            entries: [
                ['account' => '7000', 'direction' => 'DR', 'amountMinor' => 25_000],
                ['account' => '4000', 'direction' => 'CR', 'amountMinor' => 25_000],
            ],
            correlationId: 'trace-append-only-4',
            occurredAt: '2026-08-10T09:20:00+07:00',
        );

        if (! $this->onPostgres()) {
            $affected = JournalBatch::query()->where('id', $batch->id)->update(['status' => 'reversed']);

            $this->assertSame(1, $affected, 'SQLite carries no append-only trigger; the model-layer gap is still open there.');
            $this->assertSame('reversed', $batch->fresh()->status);

            return;
        }

        $this->assertMutationRefused(
            fn () => JournalBatch::query()->where('id', $batch->id)->update(['status' => 'reversed']),
        );

        $this->assertSame('posted', $batch->fresh()->status);
    }

    /**
     * Task 3 minor M5: `PostJournalBatch` returns a mutable hydrated model, and
     * `$guarded = ['*']` blocks mass-assignment but not `forceFill()->save()`.
     * The trigger is what actually closes that on PostgreSQL — the model guard
     * never covered it and this test does not pretend otherwise.
     */
    public function test_the_database_refuses_force_fill_save_on_a_hydrated_batch_task_3_m5(): void
    {
        $batch = $this->journal()->post(
            businessKey: 'payment:provider-event-app-4',
            entityRef: 'badan-usaha-1',
            sourceType: 'payment',
            sourceId: 'provider-event-app-4',
            entries: [
                ['account' => '7000', 'direction' => 'DR', 'amountMinor' => 10_000],
                ['account' => '4000', 'direction' => 'CR', 'amountMinor' => 10_000],
            ],
            correlationId: 'trace-append-only-5',
            occurredAt: '2026-08-10T09:25:00+07:00',
        );

        if (! $this->onPostgres()) {
            $batch->forceFill(['status' => 'reversed'])->save();

            $this->assertSame('reversed', $batch->fresh()->status);

            return;
        }

        $this->assertMutationRefused(
            fn () => $batch->forceFill(['status' => 'reversed'])->save(),
        );

        $this->assertSame('posted', DB::table('journal_batches')->where('id', $batch->id)->value('status'));
    }

    /**
     * AC2's four mutation shapes, at the database, on both journal tables.
     *
     * These are raw query-builder statements on purpose: the point is that the
     * refusal does not depend on going through `Journal`, on Eloquent, or on
     * any application-layer discipline at all. A `DELETE` is the shape the
     * balance trigger could never have caught even if it had been widened —
     * deleting BOTH entries of a batch leaves it "balanced" at zero.
     */
    public function test_the_database_refuses_every_update_and_delete_on_both_journal_tables(): void
    {
        $this->skipUnlessPostgres();

        $batch = $this->journal()->post(
            businessKey: 'payment:provider-event-app-6',
            entityRef: 'badan-usaha-1',
            sourceType: 'payment',
            sourceId: 'provider-event-app-6',
            entries: [
                ['account' => '7000', 'direction' => 'DR', 'amountMinor' => 40_000],
                ['account' => '4000', 'direction' => 'CR', 'amountMinor' => 40_000],
            ],
            correlationId: 'trace-append-only-6',
            occurredAt: '2026-08-10T09:30:00+07:00',
        );

        $mutations = [
            'update an entry amount' => fn () => DB::table('journal_entries')
                ->where('batch_id', $batch->id)
                ->where('direction', 'DR')
                ->update(['amount_minor' => 999]),

            // Both sides at once. This is the case that proves the control is
            // append-only enforcement and not a balance re-check: after this
            // statement the batch would still sum to zero.
            'delete both entries' => fn () => DB::table('journal_entries')
                ->where('batch_id', $batch->id)
                ->delete(),

            'update a batch' => fn () => DB::table('journal_batches')
                ->where('id', $batch->id)
                ->update(['entity_ref' => 'badan-usaha-2']),

            'delete a batch' => fn () => DB::table('journal_batches')
                ->where('id', $batch->id)
                ->delete(),
        ];

        foreach ($mutations as $label => $mutation) {
            $this->assertMutationRefused($mutation, $label);
        }

        // Nothing moved. Re-read from the database rather than from any model
        // instance already in hand.
        $row = DB::table('journal_batches')->where('id', $batch->id)->sole();
        $this->assertSame('badan-usaha-1', $row->entity_ref);
        $this->assertSame(
            [40_000, 40_000],
            DB::table('journal_entries')->where('batch_id', $batch->id)->orderBy('direction')->pluck('amount_minor')->map(intval(...))->all(),
        );
    }

    /**
     * The counterpart every "the database refuses X" test needs: the sanctioned
     * write path is untouched. A trigger that also broke `INSERT` would make
     * every test above pass for the wrong reason.
     */
    public function test_the_sanctioned_insert_path_is_unaffected_by_the_append_only_trigger(): void
    {
        $this->skipUnlessPostgres();

        $batch = $this->journal()->post(
            businessKey: 'payment:provider-event-app-7',
            entityRef: 'badan-usaha-1',
            sourceType: 'payment',
            sourceId: 'provider-event-app-7',
            entries: [
                ['account' => '7000', 'direction' => 'DR', 'amountMinor' => 60_000],
                ['account' => '4000', 'direction' => 'CR', 'amountMinor' => 60_000],
            ],
            correlationId: 'trace-append-only-7',
            occurredAt: '2026-08-10T09:35:00+07:00',
        );

        $this->assertSame(2, DB::table('journal_entries')->where('batch_id', $batch->id)->count());

        // And the reversal path, which is the sanctioned way to undo a batch —
        // it inserts a new batch and never touches the original.
        $reversal = $this->journal()->postReversal(
            originalBusinessKey: 'payment:provider-event-app-7',
            reason: 'Append-only check: corrections are posted, never edited.',
            correlationId: 'trace-append-only-8',
            occurredAt: '2026-08-10T09:40:00+07:00',
        );

        $this->assertSame($batch->id, $reversal->reverses_batch_id);
        $this->assertSame('posted', $batch->fresh()->status);
    }

    /**
     * The refusal must be the deliberate append-only policy, not any error that
     * happens to be an exception.
     *
     * This is the load-bearing part of every trigger test in this file. Without
     * the message assertion, dropping the trigger would leave these tests
     * green-for-the-wrong-reason in one direction (no exception at all is
     * caught here, so they would fail) — but with a bare
     * `expectException(QueryException::class)` a DIFFERENT database error, such
     * as a constraint that happened to fire first, would satisfy them. The
     * SQLSTATE and the message together pin the refusal to
     * `reject_journal_history_mutation()`.
     *
     * The `DB::transaction()` wrapper is NOT decoration. `RefreshDatabase`
     * already holds an open transaction, so a raised PostgreSQL error leaves it
     * ABORTED and every following statement — including this test's own "the
     * row did not move" re-read — fails with `25P02 current transaction is
     * aborted` instead of doing its job. Nesting makes each attempt a SAVEPOINT
     * that unwinds on its own. This lane has already been bitten by exactly
     * this on the duplicate-business-key contract test, where the draft passed
     * on SQLite and errored on PostgreSQL.
     */
    private function assertMutationRefused(\Closure $mutation, string $label = 'mutation'): void
    {
        try {
            DB::transaction($mutation);
            $this->fail("Expected [{$label}] to be refused by the append-only trigger, but it succeeded.");
        } catch (QueryException $exception) {
            $this->assertStringContainsString(
                'Journal history is append-only',
                $exception->getMessage(),
                "[{$label}] failed, but not with the append-only policy message: {$exception->getMessage()}",
            );
            $this->assertSame(
                '42501',
                $exception->getCode(),
                "[{$label}] must be refused as insufficient_privilege (42501), not as some other error class.",
            );
        }
    }

    private function onPostgres(): bool
    {
        return DB::connection()->getDriverName() === 'pgsql';
    }

    private function skipUnlessPostgres(): void
    {
        if (! $this->onPostgres()) {
            $this->markTestSkipped(
                'The journal append-only triggers are PostgreSQL-only; run with DB_CONNECTION=pgsql.'
            );
        }
    }

    private function journal(): Journal
    {
        return $this->app->make(Journal::class);
    }
}
