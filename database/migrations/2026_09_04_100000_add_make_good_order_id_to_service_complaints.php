<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Links a resolved `service_complaints` row to the `make_good_orders` row
 * it caused, when one was created. Today filing a complaint and creating a
 * make-good are two Actions that both happen to key off the same
 * `WorkOrder`, with no queryable link between the two — this closes that
 * real, missing relationship (spec §1, "Schema change: link a resolved
 * complaint to its make-good"). Only `ResolveComplaint` (Task 2) ever
 * writes this column.
 *
 * Explicit `->constrained()->restrictOnDelete()` FK to `make_good_orders.id`,
 * per spec §1. `work_order_id` on this same table
 * (`2026_08_17_120040_create_service_complaints_table.php`) genuinely lacks
 * a DB-level FK constraint, but `customer_id` is not a second example of
 * that pattern: `2026_08_22_100000_fix_customer_and_uploader_identity_columns.php`
 * already gave `customer_id` a real `foreignId('customer_id')->constrained('users')`
 * FK (and fixed its type from `uuid` to bigint) 13 days before this
 * migration was written. `customer_id` is the one column on this table that
 * *does* carry a real FK — this migration follows that precedent, not the
 * `work_order_id` gap.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_complaints', function (Blueprint $table): void {
            $table->foreignUuid('make_good_order_id')->nullable()->after('resolved_at')
                ->constrained('make_good_orders')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('service_complaints', function (Blueprint $table): void {
            $table->dropColumn('make_good_order_id');
        });
    }
};
