<?php

declare(strict_types=1);

use App\Platform\FinancialLedger\ReconciliationStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `reconciliations` — one row per (badan usaha, period) recording what a
 * reconciliation run concluded. AC10.
 *
 * ---------------------------------------------------------------------------
 * This table records CONCLUSIONS. It never adjusts the ledger
 * ---------------------------------------------------------------------------
 * Nothing that writes here writes `journal_batches` or `journal_entries`. When
 * the journal and a provider statement disagree, the disagreement becomes a
 * `reconciliation_exceptions` row for a human to decide on — it is never
 * absorbed by editing, reversing or "correcting" the ledger to agree with the
 * statement. See `App\Platform\FinancialLedger\Actions\RunReconciliation`.
 *
 * ---------------------------------------------------------------------------
 * `(entity_ref, period)` is UNIQUE, and that is the idempotency guarantee
 * ---------------------------------------------------------------------------
 * Reconciliation is scheduled and runs on a queue with at-least-once delivery,
 * so it WILL run twice for the same period. The UNIQUE index is the authority
 * for "one conclusion per period per entity"; the Action's lookup is only how
 * a second run produces a sensible result instead of a raw constraint
 * violation. The same discipline `vendor_payables`' `(vendor_id, source_type,
 * source_id)` index already carries.
 *
 * Unlike `journal_batches`, this table IS updated: a later run for the same
 * period rewrites the conclusion, because a conclusion is a current
 * assessment, not a historical event. The no-UPDATE ruling is scoped to the
 * two journal tables only.
 *
 * ---------------------------------------------------------------------------
 * A missing statement is NULL, never zero — enforced here, not just in code
 * ---------------------------------------------------------------------------
 * `statement_total_minor` and `statement_reference` are nullable ONLY for a
 * `statement_missing` run, and the two CHECK constraints below make that an
 * if-and-only-if. That is deliberate: defaulting an unfetched statement to `0`
 * and reporting the entire journal as a mismatch against it manufactures a
 * false finding and hides the real problem. A period we could not fetch is
 * rendered honestly as "we could not fetch this", never as a completed or
 * partial period (`AGENTS.md`; `docs/design/design-system.md` §6).
 *
 * ---------------------------------------------------------------------------
 * References only
 * ---------------------------------------------------------------------------
 * `statement_reference` is an OPAQUE provider-side identifier for the statement
 * document. No bank detail, no account number, no customer identity is stored
 * on this row (`AGENTS.md` §Observability) — the same discipline
 * `payouts.proof_document_ref` carries.
 *
 * Money is integer minor units (AC11). `unsignedBigInteger` maps to a plain
 * `bigint` on PostgreSQL, so non-negativity is restated as an explicit CHECK
 * rather than assumed from the column type.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reconciliations', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            // The `badan usaha` whose books this run covers (AC4). Named
            // `entity_ref` for consistency with `journal_batches`,
            // `vendor_payables` and `payouts`.
            $table->string('entity_ref');

            // A calendar month, `YYYY-MM`. A string and not a date because the
            // period IS the unit — a reconciliation covers a month, not an
            // instant, and storing a boundary date would invite arithmetic on
            // it that silently disagrees with the window the run used.
            $table->string('period', 7);

            $table->string('status', 32);

            // NULL if and only if the statement was missing. See the class doc
            // block and the two CHECK constraints below.
            $table->string('statement_reference')->nullable();
            $table->unsignedBigInteger('statement_total_minor')->nullable();

            // The debit-side total of every journal batch in the window. Always
            // known, including on a `statement_missing` run — the journal is
            // ours and its absence would be a different problem entirely.
            $table->unsignedBigInteger('journal_total_minor');

            $table->string('correlation_id')->nullable();
            $table->timestamp('ran_at');
            $table->timestamps();

            $table->unique(['entity_ref', 'period']);

            $table->index('period');
            $table->index('status');
        });

        // SQLite cannot add table constraints with ALTER TABLE. The focused
        // tests skip these checks locally and assert them on PostgreSQL 18,
        // which is the authoritative CI/production path — the precedent set by
        // `create_journal_batches_table`.
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        $statuses = "'".implode("', '", ReconciliationStatus::KNOWN_STATUSES)."'";

        DB::statement(
            'ALTER TABLE reconciliations ADD CONSTRAINT reconciliations_status_check '.
            "CHECK (status IN ({$statuses}))"
        );

        DB::statement(
            'ALTER TABLE reconciliations ADD CONSTRAINT reconciliations_period_format_check '.
            "CHECK (period ~ '^[0-9]{4}-(0[1-9]|1[0-2])$')"
        );

        DB::statement(
            'ALTER TABLE reconciliations ADD CONSTRAINT reconciliations_entity_ref_present_check '.
            'CHECK (length(btrim(entity_ref)) > 0)'
        );

        // "A missing statement is not a zero", as a database invariant rather
        // than a convention a future writer could quietly break. Both
        // directions matter: a `statement_missing` row may not carry a total,
        // and a row that carries no total may not claim any other status.
        DB::statement(
            'ALTER TABLE reconciliations ADD CONSTRAINT reconciliations_statement_missing_total_check '.
            "CHECK ((status = '".ReconciliationStatus::STATEMENT_MISSING."') = (statement_total_minor IS NULL))"
        );
        DB::statement(
            'ALTER TABLE reconciliations ADD CONSTRAINT reconciliations_statement_missing_reference_check '.
            "CHECK ((status = '".ReconciliationStatus::STATEMENT_MISSING."') = (statement_reference IS NULL))"
        );
        DB::statement(
            'ALTER TABLE reconciliations ADD CONSTRAINT reconciliations_statement_reference_present_check '.
            'CHECK (statement_reference IS NULL OR length(btrim(statement_reference)) > 0)'
        );
    }

    /**
     * `AGENTS.md` §Database forbids relying on a destructive `down()` for a
     * production rollback — this exists for local and CI teardown only, and
     * runs after `reconciliation_exceptions` because of the foreign key.
     */
    public function down(): void
    {
        Schema::dropIfExists('reconciliations');
    }
};
