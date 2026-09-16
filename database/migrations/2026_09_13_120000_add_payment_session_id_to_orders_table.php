<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * H-2: gives `orders` a local, creation-time handle on the payment session
 * that is currently trying to pay it, so the hourly
 * `orders:expire-stale-quotes` sweep can tell "nobody is paying this" apart
 * from "somebody is on the checkout page right now".
 *
 * ---------------------------------------------------------------------------
 * Why a column, when a join already exists on paper
 * ---------------------------------------------------------------------------
 * There IS an order/session link already:
 * `provider_events.invoice_reference` = `orders.reference`, joined to the
 * session by `provider_events.provider_transaction_id` =
 * `payment_sessions.provider_payment_id`
 * (`ApplyPaymentSettlement::resolveSessionOrFail()`). It was evaluated for
 * this fix and rejected, because `provider_events` rows are WEBHOOK RECEIPTS.
 * Walk the incident: the session is opened, the customer sits on the SumoPod
 * hosted page, the sweep fires. No webhook has arrived, so no
 * `provider_events` row exists, so that join returns the empty set at exactly
 * the moment it has to return a row. It can only name a session for an order
 * AFTER the money has already landed — the same one-step-too-late shape that
 * makes `ReleasePlotReservation`'s `isPaidOrLater()` guard inert on this path.
 *
 * A join through `correlation_id` (present on both `orders` and
 * `payment_intents`) was also rejected: it matches only because the booking
 * wizard happens to submit the order and open the session inside one HTTP
 * request, it is nullable on both sides, and it breaks on any retry that
 * lands in a later request.
 *
 * The value itself was never missing, only discarded: `OpenPaymentSession`
 * resolves the order reference and hands it to the provider as `orderId`,
 * then writes the session row without it.
 *
 * ---------------------------------------------------------------------------
 * RECONCILIATION — this contradicts two existing doc blocks, deliberately
 * ---------------------------------------------------------------------------
 * `2026_08_09_100000_create_payment_intents_table.php` says: "`payment_sessions`
 * deliberately carries no order reference either ... Adding a column here
 * would duplicate that routing and invite a second, contradictory linkage."
 * `App\Platform\Payment\SessionState`'s doc block says the same. Both stand,
 * and this migration does not overturn either, because:
 *
 *   1. Those statements are about the SETTLEMENT direction — provider back to
 *      us — where the authority genuinely is the provider's echoed
 *      `invoice_reference`. That routing is untouched here.
 *      `ApplyPaymentSettlement` still resolves its order exclusively by
 *      `invoice_reference` and never reads this column. There is no second
 *      settlement route, so there is no contradictory linkage to invite.
 *   2. This column answers the OPPOSITE question, in the opposite direction,
 *      at a time when the provider has told us nothing: "does this order have
 *      a checkout open right now?" No amount of care with the settlement
 *      route answers that, because the settlement route does not exist yet.
 *   3. The column lands on `orders` (Domain), NOT on `payment_sessions`
 *      (Platform). The Platform table's design is left exactly as its authors
 *      specified it. `payment_sessions` still carries no order reference.
 *
 * Precedent for the Domain-side shape is already in the tree and was followed
 * rather than invented: `pre_need_payment_schedules.payment_session_id`
 * (`2026_08_16_120010`) and `subscription_invoices.payment_session_id`.
 *
 * ---------------------------------------------------------------------------
 * Nullable, and `nullOnDelete`
 * ---------------------------------------------------------------------------
 * Nullable because most orders never open an online checkout at all (manual
 * fallback, operator-recorded payment, orders that never reach payment), and
 * because every row that exists today predates this column. It is expand-only:
 * nothing backfills, nothing becomes NOT NULL later without its own migration.
 *
 * `nullOnDelete` rather than `restrictOnDelete`: losing a session row must
 * never take an order with it. This is the weaker of the two guarantees on
 * purpose — the order is the record of a funeral service, the session is a
 * record of one checkout attempt, and the order outranks it. The practical
 * effect is that a deleted session degrades the sweep to its pre-fix
 * behaviour for that one order rather than erroring, which is the failure
 * direction a human would choose.
 *
 * ---------------------------------------------------------------------------
 * No index on the new column, on purpose
 * ---------------------------------------------------------------------------
 * Postgres does not auto-index a foreign key column, so this is a choice and
 * not an oversight. The only reader is `QuoteExpiryScheduler`, whose
 * `whereDoesntHave('paymentSession', ...)` compiles to a correlated
 * `NOT EXISTS` that looks the session up by `payment_sessions.id` — its
 * PRIMARY KEY, on the other side of the join. An index on `orders` would not
 * be consulted. Add one when a query appears that filters `orders` BY this
 * column; none does today.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            // Set once per checkout opening by
            // `Actions\OpenBookingOnlinePayment`, through the
            // `Order::linkPaymentSession()` door. Overwritten (not appended
            // to) when a customer retries after a failed attempt: the sweep
            // only ever asks about the CURRENT attempt, and the full history
            // of attempts lives in `audit_events` under
            // `PaymentAuditActions::SESSION_OPENED`, which is where an
            // auditor should be reading it from anyway.
            $table->foreignUuid('payment_session_id')
                ->nullable()
                ->after('paid_source_ref')
                ->constrained('payment_sessions')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('payment_session_id');
        });
    }
};
