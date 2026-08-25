<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `marketplace_order_items` — one line of a customer's order, a frozen
 * snapshot of the cart line at placement time.
 *
 * `vendor_listing_id`, `product_id`, and `product_variant_id` are all
 * `restrictOnDelete()`: a placed order must never silently lose what it was
 * priced against — the customer's committed total would render against a
 * vanished offer.
 *
 * No `vendor_order_items` table exists (void — L10's `vendor_orders` is
 * flat, one row per listing); the per-vendor subtotal is carried on
 * `vendor_orders` via the order-level lines when `PlaceMarketplaceOrder`
 * computes it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketplace_order_items', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('marketplace_order_id');
            $table->unsignedBigInteger('vendor_listing_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('product_variant_id')->nullable();
            $table->unsignedInteger('quantity');
            $table->unsignedBigInteger('unit_price_minor');
            $table->unsignedBigInteger('line_total_minor');
            $table->unsignedInteger('price_version');
            $table->timestamps();

            $table->foreign('marketplace_order_id')->references('id')->on('marketplace_orders')->cascadeOnDelete();
            $table->foreign('vendor_listing_id')->references('id')->on('vendor_listings')->restrictOnDelete();
            $table->foreign('product_id')->references('id')->on('products')->restrictOnDelete();
            $table->foreign('product_variant_id')->references('id')->on('product_variants')->restrictOnDelete();
            $table->index('marketplace_order_id');
        });

        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE marketplace_order_items ADD CONSTRAINT marketplace_order_items_quantity_positive CHECK (quantity > 0)');
        DB::statement('ALTER TABLE marketplace_order_items ADD CONSTRAINT marketplace_order_items_line_total_consistent CHECK (line_total_minor = unit_price_minor * quantity)');
    }

    public function down(): void
    {
        Schema::dropIfExists('marketplace_order_items');
    }
};
