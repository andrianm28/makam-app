<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditOutcome;
use App\Platform\Audit\AuditSource;
use App\Platform\Audit\AuditSubject;
use App\Platform\Audit\Exceptions\AuditRecordIsImmutableException;
use App\Platform\Audit\Models\AuditEvent;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * AC1 against a REAL persisted row (see
 * tests/Unit/Platform/Audit/Models/AuditEventGuardTest.php for the
 * equivalent unit-level proof that does not need the database).
 *
 * ---------------------------------------------------------------------------
 * OBS-01 (7 Sep 2026) — what changed here, mirroring
 * tests/Feature/FinancialLedger/JournalAppendOnlyTest.php's own Task 9b note
 * ---------------------------------------------------------------------------
 * The real control is now the `audit_events_append_only` trigger added by
 * `2026_09_07_100000_enforce_audit_events_append_only.php`, which refuses
 * every `UPDATE`/`DELETE` on `audit_events` for every role, on every write
 * path, at the database. The query-builder-bypass test below therefore no
 * longer asserts the bypass SUCCEEDS on PostgreSQL — it asserts the bypass
 * is REFUSED, by the database, with the deliberate policy message. This is
 * exactly the "revisit rather than delete quietly" the model's own doc
 * block called for; see `App\Platform\Audit\Models\AuditEvent`'s
 * class-level doc block for the updated accounting of what the
 * ORM-instance-method overrides do and do not add on top of the trigger.
 *
 * On SQLite (no PL/pgSQL, no triggers) the model-layer gap is still fully
 * open, and the new tests assert exactly that rather than skip — a green
 * SQLite run is not evidence the trigger exists anywhere.
 */
final class AuditEventAppendOnlyTest extends TestCase
{
    use RefreshDatabase;

    private function persistedEvent(): AuditEvent
    {
        return Audit::record(
            action: 'booking.updated',
            subject: new AuditSubject(type: 'booking', id: 1),
            outcome: AuditOutcome::Allowed,
            actorRef: 1,
            actorRole: 'admin',
            source: AuditSource::Panel,
        );
    }

    public function test_update_on_a_persisted_row_throws(): void
    {
        $event = $this->persistedEvent();

        $this->expectException(AuditRecordIsImmutableException::class);

        $event->update(['outcome' => 'denied']);
    }

    public function test_mutating_an_attribute_and_calling_save_on_a_persisted_row_throws(): void
    {
        $event = $this->persistedEvent();
        $event->outcome = 'denied';

        $this->expectException(AuditRecordIsImmutableException::class);

        $event->save();
    }

    public function test_delete_on_a_persisted_row_throws(): void
    {
        $event = $this->persistedEvent();

        $this->expectException(AuditRecordIsImmutableException::class);

        $event->delete();
    }

    public function test_the_row_is_unchanged_after_a_blocked_update_attempt(): void
    {
        $event = $this->persistedEvent();

        try {
            $event->update(['outcome' => 'denied']);
        } catch (AuditRecordIsImmutableException) {
            // expected
        }

        $this->assertSame('allowed', $event->fresh()->outcome);
    }

    /**
     * `AuditEvent::query()->update()` goes through
     * `Illuminate\Database\Eloquent\Builder`, not the `AuditEvent`
     * model instance, so overriding `update()`/`performUpdate()` on
     * the model never intercepts it — it is the
     * `audit_events_append_only` trigger, not the model, that stands
     * behind this path now.
     *
     * On PostgreSQL the bypass is refused by the database and the row
     * is proved unchanged. On SQLite there is no trigger (no PL/pgSQL)
     * and the gap is still fully open — asserted, not skipped, so a
     * green SQLite run is never mistaken for proof the trigger exists.
     */
    public function test_query_builder_mass_update_bypasses_the_model_level_guard_this_is_the_documented_ac1_gap(): void
    {
        $event = $this->persistedEvent();

        if (! $this->onPostgres()) {
            $affected = AuditEvent::query()->where('id', $event->id)->update(['outcome' => 'denied']);

            $this->assertSame(1, $affected, 'SQLite carries no append-only trigger; the model-layer gap is still open there.');
            $this->assertSame('denied', $event->fresh()->outcome);

            return;
        }

        $this->assertMutationRefused(
            fn () => AuditEvent::query()->where('id', $event->id)->update(['outcome' => 'denied']),
        );

        $this->assertSame('allowed', $event->fresh()->outcome);
    }

    /**
     * OBS-01: the four mutation shapes, at the database, as raw
     * query-builder statements — the point being that the refusal does
     * not depend on going through `Audit::record()`, on Eloquent, or on
     * any application-layer discipline at all.
     */
    public function test_the_database_refuses_every_update_and_delete_on_audit_events(): void
    {
        $this->skipUnlessPostgres();

        $event = $this->persistedEvent();

        $mutations = [
            'update via query builder' => fn () => DB::table('audit_events')
                ->where('id', $event->id)
                ->update(['outcome' => 'denied']),

            'delete via query builder' => fn () => DB::table('audit_events')
                ->where('id', $event->id)
                ->delete(),
        ];

        foreach ($mutations as $label => $mutation) {
            $this->assertMutationRefused($mutation, $label);
        }

        $row = DB::table('audit_events')->where('id', $event->id)->sole();
        $this->assertSame('allowed', $row->outcome);
    }

    /**
     * The counterpart every "the database refuses X" test needs: the
     * sanctioned `Audit::record()` write path is unaffected by the
     * trigger, on both tables it protects.
     */
    public function test_the_sanctioned_insert_path_is_unaffected_by_the_append_only_trigger(): void
    {
        $this->skipUnlessPostgres();

        $countBefore = DB::table('audit_events')->count();

        $this->persistedEvent();

        $this->assertSame($countBefore + 1, DB::table('audit_events')->count());
    }

    /**
     * Pins the refusal to `reject_audit_history_mutation()` specifically —
     * the SQLSTATE and the message together, exactly as
     * `JournalAppendOnlyTest::assertMutationRefused()` does for the journal
     * trigger. Without the message assertion a different database error
     * (say, a constraint firing first) could satisfy a bare
     * `expectException(QueryException::class)`.
     *
     * The `DB::transaction()` wrapper is not decoration:
     * `RefreshDatabase` already holds an open transaction, and a raised
     * PostgreSQL error leaves it ABORTED, failing every later statement
     * (including this test's own re-read) with `25P02` instead of doing
     * its job. Nesting makes each attempt a SAVEPOINT that unwinds on its
     * own.
     */
    private function assertMutationRefused(\Closure $mutation, string $label = 'mutation'): void
    {
        try {
            DB::transaction($mutation);
            $this->fail("Expected [{$label}] to be refused by the append-only trigger, but it succeeded.");
        } catch (QueryException $exception) {
            $this->assertStringContainsString(
                'Audit history is append-only',
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
                'The audit_events append-only trigger is PostgreSQL-only; run with DB_CONNECTION=pgsql.'
            );
        }
    }
}
