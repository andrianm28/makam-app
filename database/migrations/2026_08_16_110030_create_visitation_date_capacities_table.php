<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `visitation_date_capacities` — Task 1 of the
 * `2026-08-16-p4-memorial-qr-visitation` plan (Lane 1 — Visitation).
 * The atomic capacity ledger: one row per (policy, date) with the
 * running `booked_count`, inserted lazily by `RequestVisitation` on the
 * first booking of a date.
 *
 * This table is the serialization anchor of the no-oversell guarantee.
 * `RequestVisitation` locks the row (`lockForUpdate()`) before reading
 * `booked_count`, so two concurrent bookings for the same date serialize
 * on the row lock; on PostgreSQL the unique `(policy_id, date)` backstops
 * the first-ever-booking race, where the row does not exist yet and a
 * `lockForUpdate()` on a missing row locks nothing (no gap lock) — the
 * loser's `firstOrCreate` collides on this index and the Action's narrow
 * classifier re-reads the committed row and re-runs the capacity check.
 * See `RequestVisitation`'s class doc block for the full reasoning.
 *
 * `cascadeOnDelete` on `policy_id` — derived ledger data, same choice as
 * `visitation_blackout_dates`; deleting a policy deletes its ledger.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('visitation_date_capacities', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('policy_id')->constrained('cemetery_visitation_policies')->cascadeOnDelete();
            $table->date('date');
            $table->unsignedInteger('booked_count')->default(0);
            $table->timestamps();
            $table->unique(['policy_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('visitation_date_capacities');
    }
};
