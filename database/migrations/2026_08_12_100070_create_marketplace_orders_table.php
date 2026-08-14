<?php

declare(strict_types=1);

use App\Domain\Marketplace\PaymentState;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `marketplace_orders` — the CUSTOMER-side order root of a marketplace
 * checkout (the vendor side lives in L10's `vendor_orders`, linked through
 * the additive `marketplace_order_id` column in `2026_08_12_110015`).
 *
 * `entity_ref` is the `badan_usaha` this order's money settles under,
 * FROZEN at placement time (requirement 10) — never re-derived from a
 * mutable order row later.
 *
 * `total_minor` is stored (not derived) so a historical order renders the
 * amount the customer actually committed to, but the CHECK below keeps the
 * stored value provably equal to `subtotal_minor + delivery_fee_minor` on
 * the real engine.
 *
 * `idempotency_key` is UNIQUE: a double-submitted checkout must never
 * create a second order (design-system §6.6). The `vendor_payables`
 * UNIQUE(vendor_id, source_type, source_id) triple is the second, ledger-side
 * layer of the same guarantee.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketplace_orders', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('order_number')->unique();
            $table->string('customer_ref');
            $table->string('entity_ref');
            $table->uuid('vendor_id');
            $table->unsignedBigInteger('subtotal_minor');
            $table->unsignedBigInteger('delivery_fee_minor')->default(0);
            $table->unsignedBigInteger('total_minor');
            $table->string('payment_state', 32);
            $table->string('idempotency_key')->unique();
            $table->timestamp('placed_at');
            $table->timestamps();

            $table->foreign('vendor_id')->references('id')->on('vendors')->restrictOnDelete();
            $table->index('customer_ref');
            $table->index('vendor_id');
            $table->index('payment_state');
        });

        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE marketplace_orders ADD CONSTRAINT marketplace_orders_total_consistent CHECK (total_minor = subtotal_minor + delivery_fee_minor)');
        DB::statement('ALTER TABLE marketplace_orders ADD CONSTRAINT marketplace_orders_total_positive CHECK (total_minor > 0)');
        DB::statement("ALTER TABLE marketplace_orders ADD CONSTRAINT marketplace_orders_entity_ref_not_blank CHECK (entity_ref <> '')");

        $states = implode("', '", PaymentState::KNOWN);
        DB::statement("ALTER TABLE marketplace_orders ADD CONSTRAINT marketplace_orders_payment_state_known CHECK (payment_state IN ('{$states}'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('marketplace_orders');
    }
};
