<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive expand/contract: `scheduled_for` on `marketplace_orders`.
 *
 * The 14 Aug 2026 reconciliation moved the schedule off L10's `vendor_orders`
 * (which cannot express it) onto this lane's own customer-order root: "carry
 * the schedule and delivery fee on `marketplace_orders` (L11's own table)
 * instead". Nullable — most marketplace orders have no schedule; only
 * scheduled-service lines do.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketplace_orders', function (Blueprint $table): void {
            $table->date('scheduled_for')->nullable()->after('delivery_fee_minor');
        });
    }

    public function down(): void
    {
        Schema::table('marketplace_orders', function (Blueprint $table): void {
            $table->dropColumn('scheduled_for');
        });
    }
};
