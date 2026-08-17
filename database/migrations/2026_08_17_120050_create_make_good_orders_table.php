<?php

declare(strict_types=1);

use App\Domain\VendorFulfillment\MakeGoodStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `make_good_orders` — replacement work orders issued when the original
 * service failed or was unsatisfactory. Links the original work order to
 * its replacement and tracks the remediation lifecycle.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('make_good_orders', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('original_work_order_id');
            $table->foreignUuid('replacement_work_order_id')->nullable();
            $table->foreignUuid('original_cycle_id');

            // App\Domain\VendorFulfillment\MakeGoodStatus.
            $table->string('status', 32);

            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index('original_work_order_id', 'make_good_original_wo_index');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            $statuses = implode("', '", array_map(
                static fn (MakeGoodStatus $status): string => $status->value,
                MakeGoodStatus::cases(),
            ));

            DB::statement(
                'ALTER TABLE make_good_orders ADD CONSTRAINT make_good_orders_status_check '.
                "CHECK (status IN ('{$statuses}'))"
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('make_good_orders');
    }
};
