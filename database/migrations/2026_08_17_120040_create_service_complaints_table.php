<?php

declare(strict_types=1);

use App\Domain\VendorFulfillment\ComplaintStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `service_complaints` — customer-filed complaints about a work order.
 * Lifecycle: OPEN → INVESTIGATING → RESOLVED | DISMISSED.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_complaints', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('work_order_id');
            $table->foreignUuid('customer_id');
            $table->text('complaint_text');

            // App\Domain\VendorFulfillment\ComplaintStatus.
            $table->string('status', 32);

            $table->text('resolution_notes')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('filed_at');

            $table->timestamps();

            $table->index('work_order_id', 'service_complaints_wo_index');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            $statuses = implode("', '", array_map(
                static fn (ComplaintStatus $status): string => $status->value,
                ComplaintStatus::cases(),
            ));

            DB::statement(
                'ALTER TABLE service_complaints ADD CONSTRAINT service_complaints_status_check '.
                "CHECK (status IN ('{$statuses}'))"
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('service_complaints');
    }
};
