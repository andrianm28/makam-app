<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Purely additive. `order_parties` currently indexes only
 * `['order_id', 'role']` (`2026_08_12_100020_create_order_parties_table.php`)
 * — without this index, `Order::forUser()`'s `whereHas('parties', ...)` does
 * a sequential scan on `order_parties` on every `/akun/pesanan` load. Mirrors
 * the index `booking_drafts.user_id` already has for the identical access
 * shape.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_parties', function (Blueprint $table) {
            $table->index('user_id', 'order_parties_user_idx');
        });
    }

    public function down(): void
    {
        Schema::table('order_parties', function (Blueprint $table) {
            $table->dropIndex('order_parties_user_idx');
        });
    }
};
