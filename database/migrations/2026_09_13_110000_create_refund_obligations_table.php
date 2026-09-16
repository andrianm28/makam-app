<?php

declare(strict_types=1);

use App\Domain\RefundObligation\RefundObligationStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `refund_obligations` — Stage R0 of
 * `docs/superpowers/plans/2026-09-13-sistem-refund.md`.
 *
 * ---------------------------------------------------------------------------
 * Why a new table instead of extending `payment_reversals`
 * ---------------------------------------------------------------------------
 * `payment_reversals` answers "a reversal was DECIDED". This table answers "a
 * debt is OWED, and here is whether it has been paid". Those are different
 * questions, and the existing table's own migration doc block says so in its
 * own words: it is *"decoupled from `Journal`, `payment_sessions`, any order
 * aggregate"*, and *"`status`-style columns are deliberately absent — a
 * recorded reversal is terminal on creation"*.
 *
 * That reasoning was sound on the premise it was written under: in August
 * there was no real money-moving payment for a reversal to reference. The
 * owner's pay-in-full-upfront decision (13 Sep 2026) removed that premise —
 * money now really moves, before an admin has confirmed anything. Bolting an
 * order FK and a lifecycle onto `payment_reversals` would contradict the
 * design its own doc block defends, so this table takes the new concern and
 * leaves that one intact.
 *
 * ---------------------------------------------------------------------------
 * One obligation per order, enforced by the database
 * ---------------------------------------------------------------------------
 * `order_id` is UNIQUE. That is not tidiness — it is what makes Stage R1 safe.
 * R1 creates the obligation inside the same transaction that rejects the
 * order, and two concurrent rejections of one order must not be able to open
 * two debts against it. The unique index is the last line of that defence,
 * below the row lock `RecordOrderStatusChange` already takes.
 *
 * It also makes "has this order been refunded?" a single lookup rather than an
 * aggregate over a log — the question the current mechanism cannot answer at
 * all.
 *
 * Partial refunds have no representation here. If they are ever wanted, that
 * is a schema change with its own review, not a constraint quietly left off.
 *
 * ---------------------------------------------------------------------------
 * Status is a column, and the audit trail is `audit_events` — a deliberate
 * departure from this stage's own plan text
 * ---------------------------------------------------------------------------
 * The plan said status would be written append-only *"mengikuti disiplin yang
 * sudah dipakai `price_versions` dan `audit_events`, bukan satu kolom yang
 * ditimpa"*. Building it showed that to be the wrong call, so it is recorded
 * here rather than quietly followed.
 *
 * The machine is forward-only across exactly three states, so each transition
 * gets its own write-once column pair (`opened_*`, `executed_*`,
 * `confirmed_*`) which is never rewritten. Those columns already answer
 * "when did this change, and who did it" — the question a transitions table
 * would exist to answer — while `audit_events` carries the reason for each
 * one, because every transition runs through `Audit::wrap()`. A separate
 * transitions table would add a join to every overdue query and a second
 * append-only log beside the one this repository already keeps.
 *
 * `status` is therefore a derived cache of those timestamps, CHECK-constrained
 * to the closed list, and the reason the overdue sweep is one index scan.
 *
 * ---------------------------------------------------------------------------
 * No destination-of-funds columns, on purpose
 * ---------------------------------------------------------------------------
 * Money comes in by QRIS/e-wallet and a manual refund goes out by bank
 * transfer, so executing one means asking a grieving family for an account
 * number. That is new personal data in an already sensitive flow, and
 * `AGENTS.md` §Infrastructure-agent execution requires human review before a
 * privacy-affecting change. It belongs to Stage R2 with that review attached —
 * not to this migration, which would otherwise create the columns before
 * anyone had agreed how the data is collected, stored, or retired.
 *
 * The obligation is recordable and overdue-detectable without them. That is
 * the whole point of Stage R0: the book of debts does not wait on the question
 * of how the money moves.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refund_obligations', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // The order whose rejection created this debt. UNIQUE — see the
            // class doc block. `restrictOnDelete` because an order row must
            // not be able to take an unpaid obligation with it; orders are
            // not deleted in this system, and if that ever changes, this
            // constraint is the thing that will say so loudly.
            $table->foreignUuid('order_id')
                ->unique('refund_obligations_order_unq')
                ->constrained('orders')
                ->restrictOnDelete();

            // The payment session the money arrived through, when it is
            // known. Nullable and `nullOnDelete`: losing the provenance of a
            // payment must never destroy the record that money is owed.
            $table->foreignUuid('payment_session_id')
                ->nullable()
                ->constrained('payment_sessions')
                ->nullOnDelete();

            // NOT NULL, unlike `payment_reversals.amount_minor` which is
            // informational. An obligation whose amount is unknown cannot be
            // discharged, so it may not be created. Plain integer minor
            // units, never a float — this lane's standing money invariant.
            $table->bigInteger('amount_minor');
            $table->string('currency', 3);

            // `App\Domain\RefundObligation\RefundObligationStatus`.
            // CHECK-constrained below on Postgres, same convention and same
            // sqlite guard as `payment_reversals`.
            $table->string('status', 32);

            // 3 working days after `opened_at`, computed once by
            // `Support\WorkingDays` when the obligation is created and never
            // recomputed. Storing it rather than deriving it on read means
            // the deadline cannot silently change when the rule changes — an
            // obligation keeps the deadline it was born with.
            $table->timestamp('due_at');

            // Write-once, at creation. `opened_reason` is a real NOT NULL
            // column following `payment_reversals.reason`'s precedent: a debt
            // recorded with no stated cause is meaningless before the audit
            // layer's own mandatory-reason check even runs.
            $table->timestamp('opened_at');
            $table->string('opened_by_actor_ref')->nullable();
            $table->text('opened_reason');

            // Write-once, at Stage R2's execution. `execution_reference` is
            // the operator's transfer reference and
            // `execution_evidence_path` the stored proof; both stay null
            // until money has actually moved, which is the only thing that
            // may advance the status.
            $table->timestamp('executed_at')->nullable();
            $table->string('executed_by_actor_ref')->nullable();
            $table->string('execution_reference', 191)->nullable();
            $table->string('execution_evidence_path')->nullable();

            // Write-once, at receipt confirmation.
            $table->timestamp('confirmed_at')->nullable();
            $table->string('confirmed_by_actor_ref')->nullable();

            $table->timestamps();

            // Stage R4's sweep: "which debts are still owed and already
            // late". Leading with `status` keeps the scan on the small
            // outstanding set rather than the whole history.
            $table->index(['status', 'due_at'], 'refund_obligations_status_due_idx');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            $statuses = implode("', '", RefundObligationStatus::values());

            DB::statement(
                'ALTER TABLE refund_obligations ADD CONSTRAINT refund_obligations_status_check '.
                "CHECK (status IN ('{$statuses}'))"
            );

            // The status column is a cache of the stamps AND the evidence
            // above, so the database refuses every combination that would
            // make it a lie: executed without an execution stamp, confirmed
            // without both stamps, or either of those without the transfer
            // reference and the proof of transfer.
            //
            // The evidence columns are named here deliberately, and an
            // earlier revision of this migration did not name them. That
            // revision constrained only `executed_at`/`confirmed_at`, which
            // left the plan's own central invariant — "nothing closes an
            // obligation except a recorded execution WITH ITS EVIDENCE, not
            // an admin marking it done, not expiry" — resting on a single
            // application-layer check with no database backstop. Stage R2's
            // mutation M1 demonstrated that empirically: with the Action's
            // check removed, an evidence-free execution committed
            // successfully, because nothing below the Action objected.
            //
            // A debt owed back to a family that has already paid deserves
            // more than one enforcement point. It is the same lesson this
            // repository learned about its own design tokens: the rule with
            // a mechanical guard is the rule that gets followed.
            //
            // TERUTANG requires the evidence columns to be NULL for the same
            // reason it requires the stamps to be — proof of a transfer that
            // has not happened is not a state this ledger has a meaning for.
            DB::statement(
                'ALTER TABLE refund_obligations ADD CONSTRAINT refund_obligations_status_stamps_check '.
                "CHECK ( (status = 'TERUTANG' AND executed_at IS NULL AND confirmed_at IS NULL ".
                'AND execution_reference IS NULL AND execution_evidence_path IS NULL) '.
                "OR (status = 'DIEKSEKUSI' AND executed_at IS NOT NULL AND confirmed_at IS NULL ".
                'AND execution_reference IS NOT NULL AND execution_evidence_path IS NOT NULL) '.
                "OR (status = 'TERKONFIRMASI' AND executed_at IS NOT NULL AND confirmed_at IS NOT NULL ".
                'AND execution_reference IS NOT NULL AND execution_evidence_path IS NOT NULL) )'
            );

            // A debt of zero or less is not a debt.
            DB::statement(
                'ALTER TABLE refund_obligations ADD CONSTRAINT refund_obligations_amount_positive_check '.
                'CHECK (amount_minor > 0)'
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('refund_obligations');
    }
};
