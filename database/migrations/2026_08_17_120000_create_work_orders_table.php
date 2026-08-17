<?php

declare(strict_types=1);

use App\Domain\VendorFulfillment\WorkOrderStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `work_orders` — the primary fulfillment record for a care service visit.
 * One work order per paid subscription cycle (AC5/AC8) is the NORMAL case
 * when created from a cycle via `CreateWorkOrderFromCycle` (enforced there
 * by a check-then-create idempotency read, not a DB constraint); null
 * `subscription_cycle_id` for one-off work orders.
 *
 * Deliberately NOT a DB-level unique constraint on `subscription_cycle_id`:
 * make-good/complaint remediation (`CreateMakeGood`) issues a SECOND work
 * order for the same cycle (the replacement), which a strict 1:1 constraint
 * would reject. AC5/AC8's "one per cycle" rule is the default path's
 * behaviour, not an absolute database invariant — make-good is the
 * documented, tested exception (see `ComplaintFlowTest`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_orders', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('reference');
            $table->foreignUuid('care_plan_id');
            $table->foreignUuid('subscription_cycle_id')->nullable();
            $table->foreignUuid('vendor_id')->nullable();
            $table->foreignUuid('assigned_to')->nullable();

            // App\Domain\VendorFulfillment\WorkOrderStatus.
            $table->string('status', 32);

            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->unique('reference', 'work_orders_reference_unique');
            $table->index('care_plan_id', 'work_orders_care_plan_index');
            $table->index('subscription_cycle_id', 'work_orders_cycle_index');
            $table->index('vendor_id', 'work_orders_vendor_index');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            $statuses = implode("', '", array_map(
                static fn (WorkOrderStatus $status): string => $status->value,
                WorkOrderStatus::cases(),
            ));

            DB::statement(
                'ALTER TABLE work_orders ADD CONSTRAINT work_orders_status_check '.
                "CHECK (status IN ('{$statuses}'))"
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('work_orders');
    }
};
