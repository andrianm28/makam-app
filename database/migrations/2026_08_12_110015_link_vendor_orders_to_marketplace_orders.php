<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ruling 1 (14 Aug 2026) — the ONLY permitted extension of L10's
 * `vendor_orders` table: one additive, nullable `marketplace_order_id`
 * column linking each vendor-order row to its customer-side order root.
 *
 * L10's schema is consumed byte-for-byte otherwise: its `110000` migration
 * is never edited, no column is dropped or altered, and L10's
 * NOT NULL `customer_name`/`customer_phone`/`customer_email` and singular
 * `listing_id` shape stands. One marketplace order -> one `vendor_orders`
 * row per listing line, all sharing the order's single `vendor_id`; the
 * at-most-one-vendor guarantee is enforced at COMMIT by the
 * `vendor_orders_single_vendor` constraint trigger
 * (`2026_08_12_110020`).
 *
 * Nullable and FK-less-row-safe: L10-created vendor orders (or any row
 * written without a marketplace order) keep `marketplace_order_id = NULL`
 * and are untouched by the constraint trigger. `cascadeOnDelete()` — a
 * deleted marketplace order takes its allocation rows with it, matching the
 * customer-root lifecycle.
 *
 * Expand/contract: this migration is additive; the `down()` drops the
 * column only (never L10's table).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendor_orders', function (Blueprint $table): void {
            $table->uuid('marketplace_order_id')->nullable()->after('uuid');
            $table->index('marketplace_order_id');
        });

        Schema::table('vendor_orders', function (Blueprint $table): void {
            $table->foreign('marketplace_order_id')
                ->references('id')
                ->on('marketplace_orders')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('vendor_orders', function (Blueprint $table): void {
            $table->dropForeign(['marketplace_order_id']);
        });

        Schema::table('vendor_orders', function (Blueprint $table): void {
            $table->dropIndex(['marketplace_order_id']);
            $table->dropColumn('marketplace_order_id');
        });
    }
};
