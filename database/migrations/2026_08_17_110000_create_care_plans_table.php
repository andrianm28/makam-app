<?php

declare(strict_types=1);

use App\Domain\CareSubscription\CarePlanFrequency;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('care_plans', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('reference');
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('product_code');
            $table->string('frequency', 32);
            $table->bigInteger('price_minor');
            $table->string('currency', 8)->default('IDR');
            $table->uuid('vendor_id')->nullable();
            $table->json('checklist_template');
            $table->string('status', 32)->default('active');
            $table->timestamps();

            $table->unique('reference', 'care_plans_reference_unique');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            $frequencies = implode("', '", array_map(
                static fn (CarePlanFrequency $f): string => $f->value,
                CarePlanFrequency::cases(),
            ));

            DB::statement(
                'ALTER TABLE care_plans ADD CONSTRAINT care_plans_frequency_check '.
                "CHECK (frequency IN ('{$frequencies}'))"
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('care_plans');
    }
};
