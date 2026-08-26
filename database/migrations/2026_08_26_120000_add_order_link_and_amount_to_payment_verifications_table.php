<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Closes the PAY-02 gap `2026_08_11_100000_create_payment_verifications_table.php`
 * flagged from day one: "A future task, once `app/Domain/OrderWorkflow/`
 * exists, will very likely add a real foreign key and a migration to
 * backfill/constrain this column." `app/Domain/OrderWorkflow/` exists now,
 * but this migration does NOT point `order_id` at `orders` — see below for
 * why, and `docs/testing/release-gates.md` §C PAY-02 for the full
 * investigation this migration closes.
 *
 * ---------------------------------------------------------------------------
 * Only ONE real caller exists today, and it is marketplace, not booking
 * ---------------------------------------------------------------------------
 * Grepping every real (non-test) reference to `SubmitManualPayment::class`
 * and `PaymentVerification::createSubmitted()` turns up exactly one caller:
 * `App\Livewire\Public\Marketplace\Checkout::submitManualProof()`, which
 * writes `reference = $this->placedOrderNumber` — a `marketplace_orders.
 * order_number`. The booking wizard's own Step 8 manual-payment card
 * (`resources/views/livewire/public/booking/wizard.blade.php`) never calls
 * `SubmitManualPayment` at all: it persists the chosen payment method onto
 * the `BookingDraft` via `Actions\SaveBookingDraftStep` and stops there —
 * `app/Domain/OrderWorkflow/Actions/ApplyPaidEffects`'s own doc block
 * confirms this independently ("the manual-verification trigger stays
 * open... wiring it would mean inventing that column" — inventing being the
 * operative word, because no booking order has ever produced a
 * `payment_verifications` row to link).
 *
 * `order_id` is therefore scoped to `marketplace_orders` alone. Widening it
 * to also cover `orders` (booking) would be exactly the kind of invented
 * coverage the investigation was told not to add — if a booking-side manual
 * path is built later, it gets its own linkage decision made against
 * whatever real shape exists then, not a speculative second column added
 * here today.
 *
 * ---------------------------------------------------------------------------
 * Nullable, not NOT NULL — flagged for human follow-up
 * ---------------------------------------------------------------------------
 * `App\Platform\Payment\SubmitManualPayment` (this same change) now refuses
 * to create a row without a resolvable order and a real amount, so every
 * NEW row always carries both. Whether any PRE-EXISTING row on the live
 * beta database lacks them could not be verified from this environment (no
 * production database credentials are available to an AI agent per
 * `AGENTS.md` §Infrastructure-agent execution) — nullable is the fail-safe
 * default so this migration cannot fail or silently corrupt data against
 * live rows this change never inspected. A human reviewer should confirm
 * the live table is either empty or fully backfillable and, if so, land a
 * follow-up migration tightening both columns to NOT NULL.
 *
 * ---------------------------------------------------------------------------
 * `amount_minor` + `currency` — the same integer-minor-unit convention
 * `Actions\ApplyPaidEffects`/`PaidTrigger` already use
 * ---------------------------------------------------------------------------
 * Never a float, never a decimal string. `currency` is carried explicitly
 * even though this platform only ever configures one (`config('money.
 * currency')`) — same reasoning `PaidTrigger`'s own doc block gives for
 * carrying it despite `Money` itself having no currency of its own.
 *
 * `restrictOnDelete()` on `order_id` — same convention `order_invoices.
 * order_id` (`2026_08_26_100000_create_order_invoices_table.php`) and
 * `order_documents.order_id` already use: a marketplace order with a
 * submitted or decided payment verification must not be silently
 * deletable out from under it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_verifications', function (Blueprint $table) {
            $table->foreignUuid('order_id')
                ->nullable()
                ->after('reference')
                ->constrained('marketplace_orders')
                ->restrictOnDelete();

            $table->bigInteger('amount_minor')->nullable()->after('order_id');
            $table->string('currency', 8)->nullable()->after('amount_minor');

            $table->index('order_id', 'payment_verifications_order_id_idx');
        });

        // SQLite cannot ADD CONSTRAINT and remains this repository's
        // local/test driver — same guard every CHECK in this repo uses.
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(
            'ALTER TABLE payment_verifications ADD CONSTRAINT payment_verifications_amount_minor_positive '
            .'CHECK (amount_minor IS NULL OR amount_minor > 0)'
        );
        DB::statement(
            'ALTER TABLE payment_verifications ADD CONSTRAINT payment_verifications_currency_not_blank '
            ."CHECK (currency IS NULL OR currency <> '')"
        );
    }

    public function down(): void
    {
        Schema::table('payment_verifications', function (Blueprint $table) {
            $table->dropConstrainedForeignId('order_id');
            $table->dropColumn(['amount_minor', 'currency']);
        });
    }
};
