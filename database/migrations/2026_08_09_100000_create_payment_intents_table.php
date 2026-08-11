<?php

declare(strict_types=1);

use App\Platform\Payment\PaymentIntentDecision;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `payment_intents` — `.kiro/specs/platform-payment-adapter/design.md` §Data:
 * "payment_intents -- guard evaluation + decision record". One row per
 * `App\Platform\Payment\GuardPaymentSession` evaluation, written whether the
 * guard passes or denies (Wave 1b ruling 1b-L3-01 Step 3); under that ruling
 * every row is a denial, because the guard has no reachable pass outcome.
 *
 * Timestamp slot: this lane's assigned `2026_08_09_*` slot (the plan's
 * §File Structure, "Migrations (all additive, `2026_08_09_*`)"). Additive
 * only — this lane owns `payment_intents` and `payment_sessions` and creates
 * no table it does not own.
 *
 * ---------------------------------------------------------------------------
 * What this table deliberately does NOT reference
 * ---------------------------------------------------------------------------
 * There is no `order_id`, `case_id`, `quote_id`, `confirmation_id`,
 * `reservation_id`, `merchant_ref`, or `badan_usaha_ref` column here. Not an
 * oversight: none of those records exists anywhere in this repository (the
 * finding recorded in the plan's §Task 2 ruling, verified 10 Aug 2026), and
 * ruling 1b-L3-01 forbids inventing a parallel stand-in so a column can be
 * filled. A nullable column named after a record that does not exist would
 * be an invitation to fill it with something that is not that record. The
 * task that lands the orchestration seam adds the real references in its own
 * additive migration.
 *
 * What IS recorded is only what is truthfully known at evaluation time: the
 * amount the caller asked to charge, the server-resolved payment mode, the
 * decision, and which condition(s) produced it.
 *
 * ---------------------------------------------------------------------------
 * Append-only
 * ---------------------------------------------------------------------------
 * A decision record that can be revised is not evidence. There is no
 * `updated_at` column, for the same reason `audit_events` has none: its mere
 * existence suggests a row can be rewritten. Application-level enforcement
 * lives on `App\Platform\Payment\Models\PaymentIntent`; as with
 * `audit_events` (finding N-1), a database-level REVOKE is not available on
 * this project's single Postgres role, and that limitation is stated rather
 * than papered over.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_intents', function (Blueprint $table) {
            // UUID rather than an autoincrement id: an intent is the subject
            // of an audit event and may later be referenced from an
            // externally visible surface. A sequential integer would make
            // "how many payment attempts has this platform blocked" readable
            // from any single id. Same `HasUuids` convention as
            // `booking_drafts`.
            $table->uuid('id')->primary();

            // AC11-in-ledger / Wave 0 ruling 0c: integer minor units, never
            // a float or a decimal that a float could round-trip through.
            // `bigInteger` because IDR minor units are two orders of
            // magnitude larger than the major amount and a realistic funeral
            // package total already exceeds a 32-bit column.
            $table->bigInteger('requested_amount_minor');

            // `config('money.currency')` — pinned per row so a future
            // currency change cannot retroactively reinterpret old rows.
            $table->string('currency', 3);

            // The server-resolved `PaymentMode` value at evaluation time
            // (AC1). Snapshotted, not derived on read: the gate can be
            // opened or closed later and this row must keep saying what the
            // mode actually was when the decision was taken.
            $table->string('payment_mode', 32);

            // `PaymentIntentDecision` — CHECK-constrained below to 'denied'
            // only. See that enum's doc block for why 'allowed' is absent.
            $table->string('decision', 16);

            // The FIRST failing condition in design.md's fixed order, its
            // reason, and (for an unavailable upstream) the name of the
            // record that does not exist. All three are closed-list or
            // record-NAME values — never a record's contents.
            $table->string('denied_condition', 64)->nullable();
            $table->string('denial_reason', 32)->nullable();
            $table->string('missing_upstream', 128)->nullable();

            // EVERY failing condition, in evaluation order — a JSON list of
            // `GuardCondition` values. design.md §Observability wants "guard
            // denial reasons" plural; with five conditions unconditionally
            // denied today, reporting only the first would hide the rest.
            $table->json('denied_conditions')->nullable();

            // The public-safe explanation returned to the caller. Names a
            // condition, never a record, an identifier, or a person — see
            // `App\Platform\Payment\ConditionDenial`'s doc block.
            $table->text('public_message')->nullable();

            // Same shape and same reasoning as `audit_events.actor_ref` /
            // `actor_role`: a string reference (a future K1/K2 adapter may
            // use an external string id), nullable for a guest attempt, with
            // the role always present.
            $table->string('actor_ref')->nullable();
            $table->string('actor_role');

            $table->string('correlation_id')->nullable();

            $table->timestamp('evaluated_at');

            $table->index('evaluated_at', 'payment_intents_evaluated_at_idx');
            $table->index('actor_ref', 'payment_intents_actor_ref_idx');
            $table->index('correlation_id', 'payment_intents_correlation_idx');
            $table->index('denied_condition', 'payment_intents_denied_condition_idx');
        });

        // Real database-level enforcement of the fail-closed decision list.
        // Guarded to the pgsql driver for the same reason
        // `2026_07_26_110000_create_audit_events_table.php` guards its own
        // CHECK: SQLite's ALTER TABLE cannot ADD CONSTRAINT, and phpunit.xml
        // defaults to sqlite while CI and every real environment run
        // Postgres.
        if (DB::connection()->getDriverName() === 'pgsql') {
            $decisions = implode("', '", PaymentIntentDecision::values());

            DB::statement(
                'ALTER TABLE payment_intents ADD CONSTRAINT payment_intents_decision_check '.
                "CHECK (decision IN ('{$decisions}'))"
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_intents');
    }
};
