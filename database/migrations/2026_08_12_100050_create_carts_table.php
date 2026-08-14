<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `carts` — the customer-side basket, keyed by either an authenticated
 * customer reference or an anonymous session reference (never by a
 * client-supplied cart id).
 *
 * ---------------------------------------------------------------------------
 * `vendor_id` IS the single-vendor lock (requirement 4)
 * ---------------------------------------------------------------------------
 * Null means the cart is empty and free; non-null means it is locked to that
 * vendor. `marketplace-catalog.md` §"MVP operating constraint" fixes the MVP
 * at one vendor per checkout, and requirement 14 forbids widening this to a
 * set of vendors until order splitting, partial cancellation/refund,
 * fee/tax allocation, dispute handling, and reconciliation all exist.
 * `AddToCart` refuses to widen the lock — it reports a `CartConflict`
 * instead — and only an explicit `ReplaceCartWithVendor` call clears and
 * re-locks the cart. The DB does not enforce this alone; the application
 * lock plus the Action discipline does (a second, stronger guarantee lives
 * at the ORDER level: the `vendor_orders_single_vendor` constraint
 * trigger).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('carts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('customer_ref')->nullable();
            $table->string('session_ref')->nullable();
            $table->uuid('vendor_id')->nullable();
            $table->timestamps();

            $table->foreign('vendor_id')->references('id')->on('vendors')->nullOnDelete();
            $table->index('customer_ref');
            $table->index('session_ref');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('carts');
    }
};
