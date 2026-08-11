<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * AC2 at the database: `journal_batches` and `journal_entries` accept `INSERT`
 * and nothing else. Any `UPDATE` or `DELETE` is refused outright, for every
 * role, with a deliberate policy message.
 *
 * ---------------------------------------------------------------------------
 * Why a SEPARATE trigger, and not an extension of `assert_balanced_batch`
 * ---------------------------------------------------------------------------
 * `assert_balanced_batch` (migration `2026_08_09_110100`) is `AFTER INSERT ON
 * journal_entries` only, so a raw `UPDATE`/`DELETE` commits with no error and
 * can leave a committed batch permanently unbalanced. The obvious-looking
 * remedy — widen that trigger to `AFTER INSERT OR UPDATE OR DELETE` — is wrong
 * twice over, and both failures were established on this lane rather than
 * guessed at:
 *
 *  1. That function dereferences `NEW.batch_id`, and in PL/pgSQL `NEW` is
 *     UNASSIGNED on a row-level `DELETE`. The widened trigger would either
 *     raise `record "new" is not assigned yet` on every delete, or — if
 *     someone "fixed" that with a careless `COALESCE` — find no rows and pass
 *     with a balance of 0. That is precisely the defect class already on this
 *     lane's books as T4-M1, where a trigger branch dereferenced the wrong
 *     tuple on `DELETE`.
 *  2. More fundamentally, a balance RE-CHECK does not enforce append-only at
 *     all. Deleting BOTH sides of a batch, or scaling its debit and credit
 *     together, leaves the batch balanced and passes — so the widened trigger
 *     would still permit silent destruction of financial history.
 *
 * The two controls being conflated are AC1 ("a batch must balance") and AC2
 * ("entries are never updated or deleted"). They get one instrument each.
 * This trigger dereferences NO tuple at all, so the `NEW`/`OLD` trap cannot
 * arise here by construction.
 *
 * ---------------------------------------------------------------------------
 * Relationship to `sql/revoke-journal-mutations.sql` (sign-off bundle item 14)
 * ---------------------------------------------------------------------------
 * That file is documented as NOT EXECUTED and is blocked on finding N-1 (this
 * host provisions one PostgreSQL role per environment, so there is no separate
 * application role whose `UPDATE`/`DELETE` can be revoked). It stays that way;
 * nothing here applies it.
 *
 * This trigger is strictly STRONGER than that revoke on the one axis that
 * matters for AC2: a `REVOKE` is role-scoped and by construction can never
 * constrain the owning/migration role, while a trigger fires for every role
 * including the owner. It is also weaker on a different axis — a superuser can
 * `ALTER TABLE ... DISABLE TRIGGER`, and the owner can drop it — so the two are
 * complements, not substitutes. Neither supersedes the other and item 14 is not
 * closed by this migration.
 *
 * ---------------------------------------------------------------------------
 * Why `42501` and not `23503`/`23514`
 * ---------------------------------------------------------------------------
 * `42501 insufficient_privilege` is the SQLSTATE PostgreSQL itself raises when
 * a privilege is absent, which is exactly what this expresses: the operation is
 * not permitted, independently of whether the data would have been valid.
 *
 *  - NOT `23503` (foreign_key_violation). T4-M1's second lesson: a deliberate
 *    policy refusal dressed as an FK error sends whoever hits it looking for a
 *    missing parent row that was never the problem.
 *  - NOT `23514` (check_violation). That reads as "the values you supplied are
 *    invalid" and invites the reader to retry with different values. No values
 *    make an `UPDATE` acceptable here.
 *
 * ---------------------------------------------------------------------------
 * Teardown is unaffected — verified by running the suite, not assumed
 * ---------------------------------------------------------------------------
 * `RefreshDatabase` wraps each test in a transaction and ROLLS BACK; a rollback
 * issues no `DELETE` and fires no row trigger. `DROP TABLE` and `TRUNCATE` do
 * not fire row-level triggers either (a `TRUNCATE` trigger must be declared
 * `FOR EACH STATEMENT ... TRUNCATE`, and this is neither). Migration rollback
 * for these tables is `DROP TABLE`, which is DDL and likewise unaffected.
 *
 * `FOR EACH ROW` rather than `FOR EACH STATEMENT` is deliberate: the property
 * being protected is that no journal ROW is ever changed or removed. A
 * statement matching zero rows destroys no history, and refusing it would add a
 * failure mode with nothing behind it.
 */
return new class extends Migration
{
    public function up(): void
    {
        // SQLite has neither PL/pgSQL nor `CREATE TRIGGER ... EXECUTE
        // FUNCTION`. Same split as every other constraint in this module:
        // PostgreSQL 18 is the authoritative enforcement path in CI and
        // production, and the focused tests skip the PostgreSQL-only checks
        // locally rather than asserting a control that is not there.
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION reject_journal_history_mutation()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $function$
            BEGIN
                RAISE EXCEPTION
                    'Journal history is append-only: % on % is refused (AC2). Post a correcting or reversing batch instead.',
                    TG_OP,
                    TG_TABLE_NAME
                    USING ERRCODE = '42501';

                -- Unreachable: the RAISE above always aborts. Present so the
                -- function has an explicit terminal statement rather than
                -- relying on a reader knowing that.
                RETURN NULL;
            END;
            $function$;

            DROP TRIGGER IF EXISTS journal_batches_append_only ON journal_batches;
            CREATE TRIGGER journal_batches_append_only
            BEFORE UPDATE OR DELETE ON journal_batches
            FOR EACH ROW
            EXECUTE FUNCTION reject_journal_history_mutation();

            DROP TRIGGER IF EXISTS journal_entries_append_only ON journal_entries;
            CREATE TRIGGER journal_entries_append_only
            BEFORE UPDATE OR DELETE ON journal_entries
            FOR EACH ROW
            EXECUTE FUNCTION reject_journal_history_mutation();
            SQL);
    }

    /**
     * Reversible, and deliberately so — unlike `2026_08_10_120300`, whose
     * `down()` is a no-op (T4-M5). Dropping a trigger destroys no data, so
     * there is no destructive-rollback hazard to guard against here, and an
     * asymmetric `down()` would leave a schema state no migration describes.
     *
     * DESTRUCTIVE OF A CONTROL, NOT OF DATA: running this removes the only
     * role-independent enforcement of AC2. `AGENTS.md` §Database forbids
     * relying on destructive production `down()` migrations; this must not be
     * run against production to "unblock" a data repair. A repair that needs
     * journal history changed is a correcting batch, not an `UPDATE`.
     */
    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS journal_entries_append_only ON journal_entries;
            DROP TRIGGER IF EXISTS journal_batches_append_only ON journal_batches;
            DROP FUNCTION IF EXISTS reject_journal_history_mutation();
            SQL);
    }
};
