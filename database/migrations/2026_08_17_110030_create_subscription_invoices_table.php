<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_invoices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('subscription_cycle_id');
            $table->uuid('payment_session_id')->nullable();
            $table->bigInteger('amount_minor');
            $table->string('currency', 8);
            $table->string('status', 32);
            $table->timestamp('issued_at');
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->foreign('subscription_cycle_id')->references('id')->on('subscription_cycles');
        });

        if (config('database.default') === 'pgsql') {
            DB::statement("ALTER TABLE subscription_invoices ADD CONSTRAINT subscription_invoices_status_check CHECK (status IN ('pending', 'paid', 'failed'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_invoices');
    }
};
