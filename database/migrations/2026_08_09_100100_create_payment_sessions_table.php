<?php

declare(strict_types=1);

use App\Platform\Payment\SessionState;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `payment_sessions` — `.kiro/specs/platform-payment-adapter/design.md`
 * §Data: "payment_sessions -- provider session, merchant binding, amount
 * snapshot".
 *
 * ---------------------------------------------------------------------------
 * This table ships EMPTY, on purpose, and stays empty
 * ---------------------------------------------------------------------------
 * Wave 1b ruling 1b-L3-01 Step 3: "`payment_sessions` is created by the
 * migration but **no code path writes a row in this task** — that is the
 * point of a deny-only guard." Five of the six guard conditions
 * (confirmation/reservation, accepted quote, authorized opening, amount vs
 * quote total, merchant/`badan_usaha` binding) have no authoritative
 * upstream record in this repository, so `AGENTS.md` §Domain and financial
 * invariants — "Never create payment before valid confirmation/reservation,
 * accepted quote, and authorized opening" — cannot be honoured by any
 * creation path that could be written today.
 *
 * The schema ships now anyway because the plan assigns it to this lane and
 * because shipping it now makes the emptiness ASSERTABLE: see
 * `tests/Feature/Payment/PaymentGuardFailClosedTest.php`, which proves no
 * input reaches a pass and no row appears.
 *
 * ---------------------------------------------------------------------------
 * AC13 is enforced by the schema, not by the (absent) write path
 * ---------------------------------------------------------------------------
 * `merchant_ref` and `badan_usaha_ref` are NOT NULL. AC13 requires merchant
 * and `badan_usaha` bound on every session and re-bound on every webhook; a
 * NOT NULL column means the first creation path anyone writes cannot omit
 * them even by accident, without a schema change that a reviewer would see.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_sessions', function (Blueprint $table) {
            // UUID, not a sequential id: a session id is the natural
            // candidate for a return-URL or support-reference value, and a
            // guessable payment identifier is a disclosure risk. Same
            // reasoning as `payment_intents.id`.
            $table->uuid('id')->primary();

            // Structural link to the guard decision that authorized this
            // session. `restrictOnDelete` because deleting the decision
            // record while a session survives it would leave a payment with
            // no recorded authorization — exactly the audit gap AC2 exists
            // to close.
            $table->foreignUuid('payment_intent_id')
                ->constrained('payment_intents')
                ->restrictOnDelete();

            $table->string('provider', 32);
            $table->string('provider_payment_id', 128);
            $table->text('payment_link_url');

            // Wave 0 ruling 0c: integer minor units end to end. This is the
            // amount snapshot a webhook is later compared against
            // (AC6 `REJECTED_AMOUNT`), so it must be exact, never a float.
            $table->bigInteger('amount_minor');
            $table->string('currency', 3);

            // AC13 — see the class doc block for why these are NOT NULL.
            $table->string('merchant_ref', 128);
            $table->string('badan_usaha_ref', 128);

            // `App\Platform\Payment\SessionState` — payment-session scope,
            // never order scope. CHECK-constrained below on Postgres.
            $table->string('state', 32);

            $table->timestamp('expires_at')->nullable();

            $table->timestamps();

            // AC7's primary idempotency shape at the session level: one
            // provider payment id maps to exactly one session.
            $table->unique(['provider', 'provider_payment_id'], 'payment_sessions_provider_payment_unq');

            $table->index('state', 'payment_sessions_state_idx');
            $table->index('merchant_ref', 'payment_sessions_merchant_idx');
        });

        // Same pgsql guard and rationale as
        // `2026_07_26_110000_create_audit_events_table.php`: SQLite cannot
        // ADD CONSTRAINT, phpunit.xml defaults to sqlite, CI and every real
        // environment run Postgres.
        if (DB::connection()->getDriverName() === 'pgsql') {
            $states = implode("', '", SessionState::values());

            DB::statement(
                'ALTER TABLE payment_sessions ADD CONSTRAINT payment_sessions_state_check '.
                "CHECK (state IN ('{$states}'))"
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_sessions');
    }
};
