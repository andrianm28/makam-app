<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `cart_items` — one line of a cart.
 *
 * `unit_price_minor` and `price_version` are FROZEN at add time, not read
 * live from the listing: PUB-022 renders the price the customer actually
 * saw, and `Cart::hasStalePricing()` compares the frozen pair against the
 * listing's current pair so a price change demands explicit reconfirmation
 * before checkout instead of silently charging the new amount.
 *
 * `vendor_listing_id` and `product_variant_id` are `restrictOnDelete()`:
 * a line must never silently point at a vanished offer — the customer's
 * frozen price would render against nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cart_items', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('cart_id');
            $table->unsignedBigInteger('vendor_listing_id');
            $table->unsignedBigInteger('product_variant_id')->nullable();
            $table->unsignedInteger('quantity');
            $table->unsignedBigInteger('unit_price_minor'); // frozen at add time
            $table->unsignedInteger('price_version'); // frozen at add time
            $table->timestamps();

            $table->foreign('cart_id')->references('id')->on('carts')->cascadeOnDelete();
            $table->foreign('vendor_listing_id')->references('id')->on('vendor_listings')->restrictOnDelete();
            $table->foreign('product_variant_id')->references('id')->on('product_variants')->restrictOnDelete();
            $table->unique(['cart_id', 'vendor_listing_id', 'product_variant_id'], 'cart_items_line_unique');
        });

        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE cart_items ADD CONSTRAINT cart_items_quantity_positive CHECK (quantity > 0)');
        DB::statement('ALTER TABLE cart_items ADD CONSTRAINT cart_items_unit_price_positive CHECK (unit_price_minor > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('cart_items');
    }
};
