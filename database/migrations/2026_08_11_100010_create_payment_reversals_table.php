<?php

declare(strict_types=1);

use App\Platform\Payment\PaymentReversalType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `payment_reversals` — Task 6's safe slice
 * (`.superpowers/sdd/2026-08-09-platform-payment-adapter/task-6-brief.md`,
 * Wave 1d Append-Correction, coordinator ruling 11 Aug 2026).
 *
 * ---------------------------------------------------------------------------
 * A NEW, self-contained table — decoupled from `Journal`, `payment_sessions`,
 * any order aggregate, `payment_verifications`, and any customer-balance
 * concept
 * ---------------------------------------------------------------------------
 * `Contracts/PaymentProvider` does not exist anywhere in this branch and
 * `Journal::post()` has zero real call sites (every grep hit is inside a
 * doc comment describing the deliberate absence) — no original journal
 * batch/`business_key` has ever been created here, so a real reversal has
 * nothing to reference. This table therefore does NOT reference
 * `payment_sessions`, any order/booking table, or even
 * `payment_verifications` (Task 5's own self-contained table) — a
 * reversal in the full system would reference a real, money-moving payment
 * (a webhook-applied or manually-verified paid effect), neither of which
 * exists yet. An FK to `PaymentVerification` would misleadingly imply this
 * record is tied to a real financial effect when it structurally cannot be.
 *
 * `reference` is caller-supplied free text (the original payment's
 * identifying token) — NOT a foreign key, exactly like
 * `payment_verifications.reference`'s own deliberate, flagged design call
 * (see that migration's doc block and `task-5-report.md` §Design decision
 * flagged for the reviewer).
 *
 * `reversal_type` is `App\Platform\Payment\PaymentReversalType` — a
 * deliberately NEW, separate closed list (`REFUND|CHARGEBACK`), never
 * `PaymentVerificationStatus` or `SessionState`. Real PostgreSQL CHECK
 * constraint below, same convention as those tables' migrations — guarded
 * to `pgsql` because SQLite cannot `ALTER TABLE ... ADD CONSTRAINT` and
 * remains this repository's local/test driver.
 *
 * `reason` is a real NOT NULL column — unlike
 * `payment_verifications.decided_reason`, which is nullable at the model
 * layer with the mandatory-reason policy enforced one layer up by
 * `Audit::record()`'s `SensitiveActions::requiresReason()` check. Here the
 * column itself is also NOT NULL: a recorded reversal with no reason is
 * meaningless even before the audit layer's own check runs. This is a
 * deliberate divergence from Task 5's precedent — belt and suspenders, not
 * a re-implementation of the audit layer's check (a caller passing an
 * empty string, as opposed to null, still reaches `Audit::record()`'s
 * check and is refused there, same as Task 5). Flagged explicitly in
 * `task-6-report.md`, not presented as silently copying Task 5's shape.
 *
 * A UNIQUE constraint on `(reversal_type, reference)` is this safe slice's
 * stand-in for the original plan's "double-refund blocked (idempotent
 * business key)" test: it cannot prevent a REAL double-refund against a
 * real journal (no journal effect exists to double-spend), but it
 * genuinely prevents this lane's own record layer from recording the same
 * `(type, reference)` pair twice — the literal mechanism the test can
 * prove today. An ordinary Laravel unique index (not a Postgres-only
 * feature), so it is enforced on SQLite too.
 *
 * `status`-style columns are deliberately absent — a recorded reversal is
 * terminal on creation (no submit/decide phases like `PaymentVerification`
 * has, because there is no multi-party workflow to model without a real
 * financial effect behind it).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_reversals', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // `App\Platform\Payment\PaymentReversalType` — this table's
            // OWN closed list. CHECK-constrained below on Postgres.
            $table->string('reversal_type', 32);

            // Free-text external reference — the original payment's
            // identifying token, caller-supplied, NOT a foreign key. See
            // class doc block.
            $table->string('reference', 191);

            // Informational only — no `Money`/financial-ledger effect
            // follows from storing this. Plain integer minor units, never
            // a float, per this lane's standing money-path invariant.
            $table->bigInteger('amount_minor')->nullable();

            // Mandatory, real NOT NULL column — see class doc block for
            // why this diverges from `payment_verifications.decided_reason`.
            $table->text('reason');

            // The actor reference of whoever recorded this reversal — same
            // nullable-string convention as `payment_verifications.
            // decided_by_actor_ref`/`audit_events.actor_ref`.
            $table->string('recorded_by_actor_ref')->nullable();

            $table->timestamp('recorded_at');

            $table->timestamps();

            $table->unique(['reversal_type', 'reference'], 'payment_reversals_type_reference_unique');
            $table->index('reference', 'payment_reversals_reference_idx');
        });

        // Same pgsql guard and rationale as
        // `2026_08_11_100000_create_payment_verifications_table.php`:
        // SQLite cannot ADD CONSTRAINT, phpunit.xml defaults to sqlite, CI
        // and every real environment run Postgres.
        if (DB::connection()->getDriverName() === 'pgsql') {
            $types = implode("', '", PaymentReversalType::values());

            DB::statement(
                'ALTER TABLE payment_reversals ADD CONSTRAINT payment_reversals_reversal_type_check '.
                "CHECK (reversal_type IN ('{$types}'))"
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_reversals');
    }
};
