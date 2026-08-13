<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `vendor_availability` — a vendor's per-day schedule: how much capacity is
 * open on a given date, or whether the date is blocked outright. Gives
 * `AvailabilityMode::SCHEDULED` listings somewhere to book against, and
 * closes the "schedule" half of the requirement-2 gap described in
 * `2026_08_12_100020_create_vendor_listings_table.php`'s doc block.
 *
 * A blocked day can never also advertise capacity — enforced by the pgsql
 * CHECK below, since a "blocked but capacity > 0" row would be a
 * self-contradiction, not a data variation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendor_availability', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('vendor_id');
            $table->date('available_date');
            $table->unsignedSmallInteger('capacity')->default(0);
            $table->boolean('is_blocked')->default(false);
            $table->timestamps();

            $table->foreign('vendor_id')->references('id')->on('vendors')->restrictOnDelete();
            $table->unique(['vendor_id', 'available_date'], 'vendor_availability_day_unique');
        });

        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE vendor_availability ADD CONSTRAINT vendor_availability_capacity_zero_when_blocked CHECK ((is_blocked = false) OR (capacity = 0))');
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_availability');
    }
};
