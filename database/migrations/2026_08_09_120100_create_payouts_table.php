<?php

declare(strict_types=1);

use App\Platform\FinancialLedger\PayoutMethod;
use App\Platform\FinancialLedger\PayoutState;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `payouts` — proof that money actually left the business, and who authorised
 * it.
 *
 * ---------------------------------------------------------------------------
 * What is deliberately NOT here
 * ---------------------------------------------------------------------------
 * The spec's `design.md` column sketch reads "payable refs, method, proof,
 * approver, provider_ref, state". `provider_ref` is omitted on purpose. A
 * provider reference only exists if a provider performed a transfer, and AC9
 * forbids an automated transfer path existing at all while `G-PAYOUT-01` is
 * closed. A nullable column named for the forbidden path is an invitation to
 * fill it in; leaving it out means the schema itself has no place to record an
 * automated transfer. `PayoutState` documents the same reasoning for the
 * absent `pending`/`processing`/`failed` states.
 *
 * ---------------------------------------------------------------------------
 * Proof is a reference, never content
 * ---------------------------------------------------------------------------
 * `proof_document_kind` + `proof_document_ref` point at a document held by
 * `platform-document-vault` — a bank-transfer record, referenced, never
 * attached or copied here. `AGENTS.md` §Authorization requires payment proof
 * to be stored privately; copying any of it into this table would put
 * restricted data in a financial workflow row and, from there, into every
 * report and export that reads it.
 *
 * `proof_document_kind` is an open string with a non-blank CHECK rather than a
 * closed list, because the document-kind catalogue belongs to
 * `platform-document-vault` and that module is being built in a sibling lane
 * right now. Declaring a rival catalogue here would duplicate canonical data
 * (`AGENTS.md` §Documentation) and collide with it on merge. Reconciling this
 * column against the real catalogue is a follow-up, flagged rather than
 * guessed.
 *
 * `journal_business_key` stores the `payout:{vendor}:{payable}` key of the
 * batch this payout posted. It is a UNIQUE column here as well as in
 * `journal_batches`, so the double-post guard survives even if someone later
 * writes a payout row without going through `Actions\ManualPayout`.
 *
 * The approver's justification is NOT stored here. It lives on the
 * `VENDOR_PAYOUT` audit event, which is the canonical record for it; copying
 * it onto this row would duplicate canonical data across two hand-maintained
 * locations. `correlation_id` is how the two are tied together.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payouts', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            // UNIQUE, not just indexed: one payable is discharged once. This
            // is the database-level half of "payable is not paid out" — a
            // second payout against the same debt cannot be written at all.
            $table->foreignUuid('payable_id')->unique()->constrained('vendor_payables');

            $table->string('vendor_id');
            $table->string('entity_ref');
            $table->unsignedBigInteger('amount_minor');
            $table->string('method', 32);
            $table->string('state', 16);
            $table->string('proof_document_kind');
            $table->string('proof_document_ref');
            $table->string('approver_ref');
            $table->string('approver_role');
            $table->string('journal_business_key')->unique();
            $table->string('correlation_id')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index('vendor_id');
            $table->index('entity_ref');
            $table->index('occurred_at');
        });

        // SQLite cannot add table constraints with ALTER TABLE; PostgreSQL 18
        // is the authoritative enforcement path, per the precedent set by
        // `create_journal_batches_table`.
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        $methods = "'".implode("', '", PayoutMethod::KNOWN_METHODS)."'";
        $states = "'".implode("', '", PayoutState::KNOWN_STATES)."'";

        DB::statement(
            'ALTER TABLE payouts ADD CONSTRAINT payouts_method_check '.
            "CHECK (method IN ({$methods}))"
        );
        DB::statement(
            'ALTER TABLE payouts ADD CONSTRAINT payouts_state_check '.
            "CHECK (state IN ({$states}))"
        );
        DB::statement(
            'ALTER TABLE payouts ADD CONSTRAINT payouts_amount_minor_check '.
            'CHECK (amount_minor >= 0)'
        );

        // AC9's "recording amount, proof, approver, and reference" at the
        // database. A blank string satisfies NOT NULL, so the requirement is
        // restated as a real emptiness check: a payout row that names no
        // proof and no approver is not a payout record, it is an assertion
        // that money left with nothing behind it.
        DB::statement(
            'ALTER TABLE payouts ADD CONSTRAINT payouts_proof_kind_present_check '.
            'CHECK (length(btrim(proof_document_kind)) > 0)'
        );
        DB::statement(
            'ALTER TABLE payouts ADD CONSTRAINT payouts_proof_ref_present_check '.
            'CHECK (length(btrim(proof_document_ref)) > 0)'
        );
        DB::statement(
            'ALTER TABLE payouts ADD CONSTRAINT payouts_approver_ref_present_check '.
            'CHECK (length(btrim(approver_ref)) > 0)'
        );
        DB::statement(
            'ALTER TABLE payouts ADD CONSTRAINT payouts_approver_role_present_check '.
            'CHECK (length(btrim(approver_role)) > 0)'
        );
        DB::statement(
            'ALTER TABLE payouts ADD CONSTRAINT payouts_journal_business_key_prefix_check '.
            "CHECK (journal_business_key LIKE 'payout:%')"
        );
    }

    /**
     * Destructive by construction: dropping this table discards the proof that
     * money left the business. `AGENTS.md` §Database forbids relying on a
     * destructive `down()` for a production rollback — this is for local and CI
     * teardown only, and runs before `vendor_payables` is dropped because of
     * the foreign key.
     */
    public function down(): void
    {
        Schema::dropIfExists('payouts');
    }
};
