<?php

declare(strict_types=1);

use App\Domain\CareSubscription\SubscriptionCycleStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_cycles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('subscription_id');
            $table->date('cycle_start');
            $table->date('cycle_end');
            $table->string('status', 32);
            $table->uuid('invoice_id')->nullable();
            $table->uuid('work_order_id')->nullable();
            $table->timestamps();

            $table->foreign('subscription_id')->references('id')->on('subscriptions');
            $table->unique(
                ['subscription_id', 'cycle_start', 'cycle_end'],
                'subscription_cycles_subscription_start_end_unique'
            );
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            $statuses = implode("', '", array_map(
                static fn (SubscriptionCycleStatus $s): string => $s->value,
                SubscriptionCycleStatus::cases(),
            ));

            DB::statement(
                'ALTER TABLE subscription_cycles ADD CONSTRAINT subscription_cycles_status_check '.
                "CHECK (status IN ('{$statuses}'))"
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_cycles');
    }
};
