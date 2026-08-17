<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `work_order_tasks` — individual checklist items for a work order,
 * expanded from `care_plans.checklist_template` at work order creation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_order_tasks', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('work_order_id');
            $table->string('name');
            $table->boolean('required_evidence')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('status', 32)->default('pending');
            $table->timestamp('completed_at')->nullable();

            $table->timestamps();

            $table->index('work_order_id', 'work_order_tasks_wo_index');
        });

        if (config('database.default') === 'pgsql') {
            DB::statement("ALTER TABLE work_order_tasks ADD CONSTRAINT work_order_tasks_status_check CHECK (status IN ('pending', 'completed'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('work_order_tasks');
    }
};
