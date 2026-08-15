<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `launch_cities` — the admin-extendable table behind the platform's
 * launch-city catalogue (spec §4.6, plan Task 3 Lane B).
 *
 * `code` is the canonical uppercase city code, shared in value with
 * `LaunchCityCode`'s constants for the five MVP cities — the unique index
 * is the table's final word on duplicates. `label` is the display text
 * (the seed derives it from the code: `JAKARTA` -> `Jakarta`).
 * `is_active` removes a city from the public lists while keeping it
 * "known" to `BookingDraft` validation; `sort_order` is the display order
 * the public screens render (the five canonical rows seed 1..5 in the
 * `AGENTS.md` §Mandatory MVP UX order: Jakarta, Bogor, Depok, Tangerang,
 * Bekasi).
 *
 * Canonical five land in the paired `2026_08_15_110010_seed_launch_cities`
 * data migration — CI and deploy never run `db:seed`, so the seeder class
 * alone would never materialize them in a real environment.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('launch_cities', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('label');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('launch_cities');
    }
};
