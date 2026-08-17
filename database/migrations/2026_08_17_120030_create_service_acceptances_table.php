<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `service_acceptances` — customer acceptance of a completed work order,
 * optionally including a satisfaction rating.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_acceptances', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('work_order_id');
            $table->foreignUuid('customer_id');
            $table->timestamp('accepted_at');
            $table->unsignedTinyInteger('rating')->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index('work_order_id', 'service_acceptances_wo_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_acceptances');
    }
};
