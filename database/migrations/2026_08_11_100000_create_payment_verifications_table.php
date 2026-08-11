<?php

declare(strict_types=1);

use App\Platform\Payment\PaymentVerificationStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `payment_verifications` — Task 5's safe slice
 * (`.superpowers/sdd/2026-08-09-platform-payment-adapter/task-5-brief.md`,
 * Wave 1c Append-Correction, coordinator ruling 11 Aug 2026).
 *
 * ---------------------------------------------------------------------------
 * A NEW, self-contained table — deliberately decoupled from both blocked
 * state machines
 * ---------------------------------------------------------------------------
 * NO foreign key to `payment_sessions` and NO foreign key to any
 * order/booking table. `GuardResult::isAllowed()` is always false and
 * `app/Domain/OrderWorkflow/` is empty (see `SessionState`'s own doc block
 * and this lane's `progress.md`), so a `payment_verifications` row cannot
 * and does not reference either. `reference` below plays the same
 * "ownerType/ownerId agnostic" role `Actions\UploadDocument` already uses
 * for `documents.owner_type`/`owner_id` — a free-text pointer, not a
 * relational constraint.
 *
 * `reference` is caller-supplied free text (an order/booking/invoice
 * token) — NOT a foreign key. This is a deliberate, FLAGGED design call,
 * not a settled production shape: see `task-5-report.md` §Design decision
 * flagged for the reviewer. A future task, once `app/Domain/OrderWorkflow/`
 * exists, will very likely add a real foreign key and a migration to
 * backfill/constrain this column.
 *
 * `proof_document_id` DOES get a real foreign key, to `documents.id` —
 * that table is real on this branch (Platform Document Vault, L1, merged
 * via commit `db864be`) and is not one of the two blocked walls this
 * migration avoids. `restrictOnDelete()` because a payment verification's
 * evidence must not silently disappear out from under a decided row —
 * same reasoning `payment_sessions.payment_intent_id` already uses.
 *
 * `status` is `App\Platform\Payment\PaymentVerificationStatus` — a
 * deliberately NEW, separate closed list (`SUBMITTED|VERIFIED|REJECTED`),
 * never `App\Platform\Payment\SessionState`. Real PostgreSQL CHECK
 * constraint below, same convention as `SessionState`/`ProviderEventStatus`
 * — guarded to `pgsql` because SQLite cannot `ALTER TABLE ... ADD
 * CONSTRAINT` and remains this repository's local/test driver.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_verifications', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Free-text external reference — NOT a foreign key. See class
            // doc block.
            $table->string('reference', 191);

            $table->string('payment_method', 64);
            $table->string('payment_reference', 191);
            $table->text('instructions')->nullable();

            // Reference only, never the file — proof content lives entirely
            // inside the document vault's own quarantine/scan/promote
            // lifecycle. Nullable: the proof upload is optional per the
            // brief ("optional proof upload").
            $table->foreignUuid('proof_document_id')
                ->nullable()
                ->constrained('documents')
                ->restrictOnDelete();

            // `App\Platform\Payment\PaymentVerificationStatus` — this
            // table's OWN state machine. CHECK-constrained below on
            // Postgres.
            $table->string('status', 32);

            $table->timestamp('submitted_at');
            $table->timestamp('decided_at')->nullable();
            $table->text('decided_reason')->nullable();

            // The actor reference of whoever decided this row — same
            // nullable-string convention as `audit_events.actor_ref`
            // (int|string identity reference, stored as string).
            $table->string('decided_by_actor_ref')->nullable();

            $table->timestamps();

            $table->index('status', 'payment_verifications_status_idx');
            $table->index('reference', 'payment_verifications_reference_idx');
        });

        // Same pgsql guard and rationale as
        // `2026_08_09_100100_create_payment_sessions_table.php`: SQLite
        // cannot ADD CONSTRAINT, phpunit.xml defaults to sqlite, CI and
        // every real environment run Postgres.
        if (DB::connection()->getDriverName() === 'pgsql') {
            $statuses = implode("', '", PaymentVerificationStatus::values());

            DB::statement(
                'ALTER TABLE payment_verifications ADD CONSTRAINT payment_verifications_status_check '.
                "CHECK (status IN ('{$statuses}'))"
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_verifications');
    }
};
