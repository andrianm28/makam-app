<?php

declare(strict_types=1);

use App\Platform\FinancialLedger\VendorPayableState;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `vendor_payables` — what we owe a vendor, and how far along the three-state
 * AC8 lifecycle that debt is.
 *
 * ---------------------------------------------------------------------------
 * This table is NOT append-only, and that is deliberate
 * ---------------------------------------------------------------------------
 * The Wave 1b ruling that forbids `UPDATE` on `journal_batches` and
 * `journal_entries`, and derives reversed-ness rather than storing it, is
 * scoped to the JOURNAL tables. It does not extend here, and contorting this
 * table to match it would be a misreading of why that ruling exists.
 *
 * The journal is append-only because it is the immutable money record: an
 * entry describes something that already happened, so editing it rewrites
 * history (AC2, AC14). A payable is a workflow row describing an obligation
 * that genuinely moves — `held` today, `payable` once evidence is accepted and
 * the dispute window closes, `paid` once a payout is recorded. `state` is a
 * real mutable state machine and `UPDATE`ing it is the correct expression of
 * that. The immutable record of the money actually moving is the journal batch
 * `Actions\ManualPayout::pay()` posts, which is where append-only applies.
 *
 * ---------------------------------------------------------------------------
 * Columns
 * ---------------------------------------------------------------------------
 * `entity_ref` is not in the spec's own `design.md` column sketch
 * ("vendor, source order, eligible_at, amount_minor, state") and is added
 * deliberately: AC4 requires every journal batch to bind to an explicit
 * `badan_usaha`/merchant entity, and the payout batch's entity has to come
 * from somewhere at payout time. Deriving it from the order later would mean
 * the ledger's entity binding depended on a mutable order row, which AC6
 * forbids. Carrying it on the payable freezes it at assessment time.
 *
 * `source_type`/`source_id` point at the record the debt arose from (a
 * marketplace order today). This is NOT `journal_batches.source_type` and
 * shares none of its closed list — that column classifies a money movement,
 * this one classifies an originating record — so it is deliberately left an
 * open string with no CHECK: no spec has fixed the list of things that can
 * create a vendor debt, and guessing one here would be a rival catalogue.
 *
 * `amount_minor` is an integer number of minor units (AC11). `unsignedBigInteger`
 * maps to a plain `bigint` on PostgreSQL, so the non-negativity is restated as
 * an explicit CHECK below rather than assumed from the column type — the same
 * thing `create_journal_entries_table` does for the identical reason.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendor_payables', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('vendor_id');
            $table->string('entity_ref');
            $table->string('source_type');
            $table->string('source_id');
            $table->unsignedBigInteger('amount_minor');
            $table->string('state', 16);
            $table->timestamp('eligible_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->string('correlation_id')->nullable();
            $table->timestamps();

            // One debt per (vendor, source record). Re-assessing the same
            // source must update the existing row, never open a second
            // obligation for work that was only done once. This UNIQUE index
            // is the authority for that; `Actions\VendorPayable::assess()`'s
            // lookup is only how it produces a sensible result rather than a
            // raw constraint violation.
            $table->unique(['vendor_id', 'source_type', 'source_id']);

            $table->index('vendor_id');
            $table->index('entity_ref');
            $table->index('state');
            $table->index('eligible_at');
        });

        // SQLite cannot add table constraints with ALTER TABLE. The focused
        // tests skip these checks locally and assert them on PostgreSQL 18,
        // which is the authoritative CI/production path — the precedent set by
        // `create_journal_batches_table`.
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        $states = "'".implode("', '", VendorPayableState::KNOWN_STATES)."'";

        DB::statement(
            'ALTER TABLE vendor_payables ADD CONSTRAINT vendor_payables_state_check '.
            "CHECK (state IN ({$states}))"
        );
        DB::statement(
            'ALTER TABLE vendor_payables ADD CONSTRAINT vendor_payables_amount_minor_check '.
            'CHECK (amount_minor >= 0)'
        );

        // AC8 at the database, not only in the Action: the three states are
        // distinguishable by their own columns, so a row cannot claim to be
        // payable without a recorded eligibility moment, or claim to be paid
        // without a recorded payment moment. A future writer that bypasses
        // `Actions\VendorPayable` entirely still cannot merge the states.
        DB::statement(
            'ALTER TABLE vendor_payables ADD CONSTRAINT vendor_payables_eligible_at_check '.
            "CHECK ((state = '".VendorPayableState::HELD."') = (eligible_at IS NULL))"
        );
        DB::statement(
            'ALTER TABLE vendor_payables ADD CONSTRAINT vendor_payables_paid_at_check '.
            "CHECK ((state = '".VendorPayableState::PAID."') = (paid_at IS NOT NULL))"
        );
    }

    /**
     * Destructive by construction: dropping this table discards every recorded
     * vendor obligation. `AGENTS.md` §Database is explicit that a production
     * rollback must not rely on a destructive `down()` — this exists for local
     * and CI teardown only. AC14's rollback safety comes from the migration
     * being additive on the way up, never from running this.
     */
    public function down(): void
    {
        Schema::dropIfExists('vendor_payables');
    }
};
