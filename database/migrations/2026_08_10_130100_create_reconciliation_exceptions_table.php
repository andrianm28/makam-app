<?php

declare(strict_types=1);

use App\Platform\FinancialLedger\ReconciliationDecision;
use App\Platform\FinancialLedger\ReconciliationExceptionStatus;
use App\Platform\FinancialLedger\ReconciliationExceptionType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `reconciliation_exceptions` — one row per difference between the journal and
 * a provider statement, and the record of who decided what about it. AC10,
 * AC12.
 *
 * ---------------------------------------------------------------------------
 * `(entity_ref, period, type, subject_ref)` is the natural key
 * ---------------------------------------------------------------------------
 * Reconciliation is scheduled and runs on an at-least-once queue, so the same
 * period WILL be reconciled twice and the same difference WILL be found twice.
 * A duplicate exception row is not a cosmetic problem: it puts the same finding
 * in front of a human twice, and lets one copy be resolved while the other
 * stays open and keeps the period reading `exceptions_open` forever.
 *
 * The UNIQUE index below is the authority for that, not the Action — an
 * `insertOrIgnore` against it is race-safe, whereas a read-then-insert check in
 * PHP is not. The key is stated in natural terms (`entity_ref`, `period`) rather
 * than on the surrogate `reconciliation_id`, so it keeps holding even if a
 * reconciliation row is ever recreated.
 *
 * A re-run therefore never touches an existing row: it does not reopen a
 * resolved exception, does not overwrite a recorded decision, and does not
 * rewrite the amounts a human already looked at.
 *
 * ---------------------------------------------------------------------------
 * `status` is mutable and `journal_batches` is not — both on purpose
 * ---------------------------------------------------------------------------
 * The Wave 1b no-UPDATE ruling is scoped to `journal_batches`/`journal_entries`
 * ONLY. Over-generalising it here would make this table unable to record a
 * decision at all. The single transition anything performs is `open ->
 * resolved`, once; nothing re-edits a resolved row, so Task 6 can revoke UPDATE
 * on a resolved exception at the database role level without breaking any path.
 *
 * A `post_correction` decision does NOT edit the ledger. The correction is a
 * NEW batch posted through `Contracts\Journal` inside the same transaction as
 * the resolution.
 *
 * ---------------------------------------------------------------------------
 * NULL means "nothing was observed", never zero
 * ---------------------------------------------------------------------------
 * `journal_amount_minor` is NULL on a `missing` finding (the journal has no
 * batch for it) and `statement_amount_minor` is NULL on an `extra` or
 * `unbalanced` one. Writing `0` there would assert we observed a zero, which is
 * a different and false claim — the same reasoning `reconciliations`' own
 * statement-missing constraints record. The per-type CHECK constraints below
 * make each of those an invariant rather than a convention.
 *
 * ---------------------------------------------------------------------------
 * References only
 * ---------------------------------------------------------------------------
 * `subject_ref` is an OPAQUE reference — a `journal_batches.business_key` for a
 * journal-side finding, a provider statement line reference for a statement-side
 * one. No bank detail, no account number and no customer identity ever enters
 * this table (`AGENTS.md` §Observability). `decided_by` is an identity
 * REFERENCE, the same shape `payouts.approver_ref` carries.
 *
 * The decider's justification is NOT stored here. It lives on the
 * `RECONCILIATION_EXCEPTION_RESOLVED` audit event, which is the canonical
 * record for it; copying it onto this row would duplicate canonical data across
 * two hand-maintained locations (`AGENTS.md` §Documentation). `correlation_id`
 * ties the two together.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reconciliation_exceptions', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            // Convenience link to the run that found it. Existence of the
            // parent is enforced on both drivers by this foreign key; the
            // natural key below is what enforces uniqueness.
            $table->foreignUuid('reconciliation_id')->constrained('reconciliations');

            $table->string('entity_ref');
            $table->string('period', 7);
            $table->string('type', 32);
            $table->string('subject_ref');

            $table->unsignedBigInteger('journal_amount_minor')->nullable();
            $table->unsignedBigInteger('statement_amount_minor')->nullable();

            $table->string('status', 16);

            // All three are set together, exactly once, by
            // `Actions\ResolveException`. The PostgreSQL CHECKs below make
            // "resolved" and "has a decider, a moment and a decision" the same
            // statement, so no writer can record half a decision.
            $table->string('decision', 32)->nullable();
            $table->string('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();

            $table->string('correlation_id')->nullable();
            $table->timestamps();

            $table->unique(['entity_ref', 'period', 'type', 'subject_ref']);

            $table->index('status');
            $table->index(['entity_ref', 'period']);
            $table->index('type');
        });

        // SQLite cannot add table constraints with ALTER TABLE. The focused
        // tests skip these checks locally and assert them on PostgreSQL 18,
        // which is the authoritative CI/production path — the precedent set by
        // `create_journal_batches_table`.
        //
        // Note what is NOT here: no `DEFERRABLE INITIALLY DEFERRED` constraint
        // trigger. Every invariant this table needs is expressible as an
        // ordinary row-local CHECK or a UNIQUE/foreign key, all of which fire
        // immediately and are therefore provable under `RefreshDatabase`. A
        // deferred trigger only fires at COMMIT, which the test harness rolls
        // back, so its positive path could not honestly be reported as tested —
        // a gap this lane has already been burned by on
        // `enforce_vendor_payable_payout_consistency`. Adding one here would
        // have bought a cross-row check at the price of an untestable one.
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        $types = "'".implode("', '", ReconciliationExceptionType::KNOWN_TYPES)."'";
        $statuses = "'".implode("', '", ReconciliationExceptionStatus::KNOWN_STATUSES)."'";
        $decisions = "'".implode("', '", ReconciliationDecision::KNOWN_DECISIONS)."'";
        $resolved = ReconciliationExceptionStatus::RESOLVED;

        DB::statement(
            'ALTER TABLE reconciliation_exceptions ADD CONSTRAINT reconciliation_exceptions_type_check '.
            "CHECK (type IN ({$types}))"
        );
        DB::statement(
            'ALTER TABLE reconciliation_exceptions ADD CONSTRAINT reconciliation_exceptions_status_check '.
            "CHECK (status IN ({$statuses}))"
        );
        DB::statement(
            'ALTER TABLE reconciliation_exceptions ADD CONSTRAINT reconciliation_exceptions_decision_check '.
            "CHECK (decision IS NULL OR decision IN ({$decisions}))"
        );
        DB::statement(
            'ALTER TABLE reconciliation_exceptions ADD CONSTRAINT reconciliation_exceptions_period_format_check '.
            "CHECK (period ~ '^[0-9]{4}-(0[1-9]|1[0-2])$')"
        );
        DB::statement(
            'ALTER TABLE reconciliation_exceptions ADD CONSTRAINT reconciliation_exceptions_subject_ref_present_check '.
            'CHECK (length(btrim(subject_ref)) > 0)'
        );

        // AC12 at the database: a resolved exception has a decider, a moment
        // and a decision; an open one has none of the three. A future writer
        // that bypasses `Actions\ResolveException` entirely still cannot record
        // a decision with nobody attached to it, or mark something resolved
        // that nobody decided.
        DB::statement(
            'ALTER TABLE reconciliation_exceptions ADD CONSTRAINT reconciliation_exceptions_decided_by_check '.
            "CHECK ((status = '{$resolved}') = (decided_by IS NOT NULL))"
        );
        DB::statement(
            'ALTER TABLE reconciliation_exceptions ADD CONSTRAINT reconciliation_exceptions_decided_at_check '.
            "CHECK ((status = '{$resolved}') = (decided_at IS NOT NULL))"
        );
        DB::statement(
            'ALTER TABLE reconciliation_exceptions ADD CONSTRAINT reconciliation_exceptions_decision_present_check '.
            "CHECK ((status = '{$resolved}') = (decision IS NOT NULL))"
        );
        DB::statement(
            'ALTER TABLE reconciliation_exceptions ADD CONSTRAINT reconciliation_exceptions_decided_by_present_check '.
            'CHECK (decided_by IS NULL OR length(btrim(decided_by)) > 0)'
        );

        // A finding has to be ABOUT something. A row with neither side
        // observed describes no difference at all.
        DB::statement(
            'ALTER TABLE reconciliation_exceptions ADD CONSTRAINT reconciliation_exceptions_some_amount_check '.
            'CHECK (journal_amount_minor IS NOT NULL OR statement_amount_minor IS NOT NULL)'
        );

        // Per-type shape. Each of these is the "a missing thing is not a zero"
        // rule applied to one member of the closed list.
        DB::statement(
            'ALTER TABLE reconciliation_exceptions ADD CONSTRAINT reconciliation_exceptions_missing_shape_check '.
            "CHECK (type <> '".ReconciliationExceptionType::MISSING."' OR ".
            '(journal_amount_minor IS NULL AND statement_amount_minor IS NOT NULL))'
        );
        DB::statement(
            'ALTER TABLE reconciliation_exceptions ADD CONSTRAINT reconciliation_exceptions_extra_shape_check '.
            "CHECK (type <> '".ReconciliationExceptionType::EXTRA."' OR ".
            '(statement_amount_minor IS NULL AND journal_amount_minor IS NOT NULL))'
        );
        DB::statement(
            'ALTER TABLE reconciliation_exceptions ADD CONSTRAINT reconciliation_exceptions_unbalanced_shape_check '.
            "CHECK (type <> '".ReconciliationExceptionType::UNBALANCED."' OR ".
            '(statement_amount_minor IS NULL AND journal_amount_minor IS NOT NULL))'
        );

        // A recorded mismatch that is not a mismatch is a false finding, and a
        // false finding costs a human's time and credibility. Both sides must
        // be observed AND they must actually differ.
        DB::statement(
            'ALTER TABLE reconciliation_exceptions ADD CONSTRAINT reconciliation_exceptions_amount_mismatch_shape_check '.
            "CHECK (type <> '".ReconciliationExceptionType::AMOUNT_MISMATCH."' OR ".
            '(journal_amount_minor IS NOT NULL AND statement_amount_minor IS NOT NULL '.
            'AND journal_amount_minor <> statement_amount_minor))'
        );
    }

    /**
     * `AGENTS.md` §Database forbids relying on a destructive `down()` for a
     * production rollback — this exists for local and CI teardown only, and
     * runs before `reconciliations` because of the foreign key.
     */
    public function down(): void
    {
        Schema::dropIfExists('reconciliation_exceptions');
    }
};
