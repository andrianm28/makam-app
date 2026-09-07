<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * OBS-01: `audit_events` and `document_access_events` had NO database-level
 * append-only enforcement — only the Eloquent-level guard on
 * `App\Platform\Audit\Models\AuditEvent` (`update()`/`performUpdate()`/
 * `delete()` all throw) and an `outcome` CHECK constraint. That guard is
 * documented, in `AuditEvent`'s own class doc block, as NOT stopping
 * `AuditEvent::query()->update(...)`, raw SQL, or a second ORM/driver
 * instance — and `tests/Feature/Audit/AuditEventAppendOnlyTest.php`'s own
 * `test_query_builder_mass_update_bypasses_the_model_level_guard_this_is_the_documented_ac1_gap`
 * proves that bypass SUCCEEDS today. `document_access_events` has the
 * identical gap and no test even documenting it.
 *
 * This is exactly the same shape as `journal_batches`/`journal_entries`
 * (`2026_08_11_100000_enforce_journal_append_only.php`) and
 * `notification_template_versions`/`document_scans`, which all carry real
 * `BEFORE UPDATE OR DELETE` triggers rather than relying on an
 * ORM-instance-method override. This migration closes the same gap for the
 * two remaining append-only tables using the exact same mechanism —
 * deliberately not a new pattern. See that migration's own doc block for the
 * full reasoning (why a trigger, why `42501`, why `FOR EACH ROW`, why
 * teardown via `RefreshDatabase`/`DROP TABLE` is unaffected); it is not
 * repeated here.
 *
 * A separate trigger FUNCTION (`reject_audit_history_mutation`, not a reuse
 * of `reject_journal_history_mutation`) because the two are conceptually
 * unrelated append-only domains (audit trail vs. financial ledger) and
 * `TG_TABLE_NAME` already makes the raised message correct per-table without
 * any coupling between them — a future change to one domain's policy text
 * must not silently reword the other's.
 */
return new class extends Migration
{
    public function up(): void
    {
        // SQLite (the local/unit-test driver) has neither PL/pgSQL nor
        // `CREATE TRIGGER ... EXECUTE FUNCTION`. PostgreSQL 18 is the
        // authoritative enforcement path in CI and production; focused
        // tests skip the PostgreSQL-only checks locally rather than
        // asserting a control that is not there — same split as every
        // other constraint in this module.
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION reject_audit_history_mutation()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $function$
            BEGIN
                RAISE EXCEPTION
                    'Audit history is append-only: % on % is refused. Record a new event instead.',
                    TG_OP,
                    TG_TABLE_NAME
                    USING ERRCODE = '42501';

                -- Unreachable: the RAISE above always aborts. Present so the
                -- function has an explicit terminal statement rather than
                -- relying on a reader knowing that.
                RETURN NULL;
            END;
            $function$;

            DROP TRIGGER IF EXISTS audit_events_append_only ON audit_events;
            CREATE TRIGGER audit_events_append_only
            BEFORE UPDATE OR DELETE ON audit_events
            FOR EACH ROW
            EXECUTE FUNCTION reject_audit_history_mutation();

            DROP TRIGGER IF EXISTS document_access_events_append_only ON document_access_events;
            CREATE TRIGGER document_access_events_append_only
            BEFORE UPDATE OR DELETE ON document_access_events
            FOR EACH ROW
            EXECUTE FUNCTION reject_audit_history_mutation();
            SQL);
    }

    /**
     * Reversible, and deliberately so — dropping a trigger destroys no data.
     *
     * DESTRUCTIVE OF A CONTROL, NOT OF DATA: running this removes the only
     * role-independent enforcement of append-only audit/access history.
     * `AGENTS.md` §Database forbids relying on destructive production
     * `down()` migrations; this must not be run against production to
     * "unblock" a data repair.
     */
    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS document_access_events_append_only ON document_access_events;
            DROP TRIGGER IF EXISTS audit_events_append_only ON audit_events;
            DROP FUNCTION IF EXISTS reject_audit_history_mutation();
            SQL);
    }
};
