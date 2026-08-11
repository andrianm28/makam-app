<?php

declare(strict_types=1);

namespace Tests\Feature\FinancialLedger;

use App\Platform\FinancialLedger\Journal;
use App\Platform\FinancialLedger\Models\JournalBatch;
use App\Platform\FinancialLedger\Models\JournalEntry;
use Illuminate\Database\Events\QueryExecuted;
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
 * therefore SUCCEEDS today, and this test says so out loud rather than
 * pretending the model guard is the control.
 *
 * The real control is the database-level `REVOKE` documented in
 * `sql/revoke-journal-mutations.sql`, which is reference-only until finding
 * N-1 is resolved (no distinct application/migration Postgres role exists —
 * see that file's header). Exactly like the Audit twin, the mass-update
 * test below asserts the bypass SUCCEEDS on purpose: that assertion is the
 * visible marker of the documented gap, and it should START failing at the
 * database (a permission error) the day the REVOKE is applied for real —
 * at which point this test must be revisited, not deleted quietly.
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

        $this->assertGreaterThan(0, $statements, 'The write path must actually touch the database.');

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
     * THE DOCUMENTED GAP, made concrete rather than left as prose only.
     * `JournalBatch::query()->update()` goes through
     * `Illuminate\Database\Eloquent\Builder`, not a model override, so no
     * model-level guard intercepts it — exactly the same reason the Audit
     * twin's `test_query_builder_mass_update_bypasses_the_model_level_guard...`
     * exists. Real enforcement requires the database-level `REVOKE` in
     * `sql/revoke-journal-mutations.sql`, blocked on finding N-1.
     *
     * This test currently asserts the bypass SUCCEEDS. That is not this test
     * endorsing the gap — it is this test refusing to let the gap go
     * unnoticed, and it should start failing at the database (a permission
     * error for any non-migration role) once N-1 is resolved and the REVOKE
     * is applied.
     */
    public function test_query_builder_mass_update_bypasses_the_model_guard_this_is_the_documented_ac2_gap(): void
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

        $affected = JournalBatch::query()->where('id', $batch->id)->update(['status' => 'reversed']);

        $this->assertSame(1, $affected);
        $this->assertSame('reversed', $batch->fresh()->status);
    }

    /**
     * Task 3 minor M5, carried forward: `PostJournalBatch` returns a mutable
     * hydrated model, and `$guarded = ['*']` blocks mass-assignment but not
     * `forceFill()->save()`. This test pins the current honest behaviour and
     * names `sql/revoke-journal-mutations.sql` as the control that actually
     * closes M5 — the checklist item is satisfied by the revoke, not by this
     * test pretending the guard covers `forceFill()`.
     */
    public function test_force_fill_save_on_a_hydrated_batch_is_not_blocked_this_is_task_3_m5(): void
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

        $batch->forceFill(['status' => 'reversed'])->save();

        $this->assertSame('reversed', $batch->fresh()->status);
    }

    private function journal(): Journal
    {
        return $this->app->make(Journal::class);
    }
}
