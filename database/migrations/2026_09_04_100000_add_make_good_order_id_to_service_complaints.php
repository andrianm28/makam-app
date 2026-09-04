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
 * No explicit `.constrained()`/FK constraint, matching this table's own
 * existing `work_order_id`/`customer_id` columns
 * (`2026_08_17_120040_create_service_complaints_table.php`) — neither of
 * those carries a DB-level FK constraint either, despite being declared
 * `foreignUuid`; this migration follows the same established (if
 * debatable) shape for consistency rather than introducing a new
 * constraint discipline mid-table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_complaints', function (Blueprint $table): void {
            $table->foreignUuid('make_good_order_id')->nullable()->after('resolved_at');
        });
    }

    public function down(): void
    {
        Schema::table('service_complaints', function (Blueprint $table): void {
            $table->dropColumn('make_good_order_id');
        });
    }
};
