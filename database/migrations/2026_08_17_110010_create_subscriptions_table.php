<?php

declare(strict_types=1);

use App\Domain\CareSubscription\SubscriptionStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('reference');
            $table->uuid('grave_id');
            $table->uuid('care_plan_id');
            $table->uuid('customer_id');
            $table->string('status', 32);
            $table->string('frequency', 32);
            $table->bigInteger('price_minor');
            $table->string('currency', 8);
            $table->unsignedInteger('current_cycle_number')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('paused_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->unique('reference', 'subscriptions_reference_unique');
            $table->foreign('care_plan_id')->references('id')->on('care_plans');
            $table->index(['grave_id'], 'subscriptions_grave_index');
            $table->index(['customer_id'], 'subscriptions_customer_index');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            $statuses = implode("', '", array_map(
                static fn (SubscriptionStatus $s): string => $s->value,
                SubscriptionStatus::cases(),
            ));

            DB::statement(
                'ALTER TABLE subscriptions ADD CONSTRAINT subscriptions_status_check '.
                "CHECK (status IN ('{$statuses}'))"
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
