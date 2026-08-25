<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `cemetery_visitation_policies` — Task 1 of the
 * `2026-08-16-p4-memorial-qr-visitation` plan (Lane 1 — Visitation).
 * The per-cemetery visiting-hours template and daily capacity that
 * `RequestVisitation` enforces.
 *
 * ---------------------------------------------------------------------------
 * `operating_hours` — JSON, allowlisted weekday keys, not a schedule table
 * ---------------------------------------------------------------------------
 * The design spec §4.1 names this column a "weekday operating-hours
 * template (JSON columns, allowlisted keys)". One row per cemetery, with
 * the seven weekday keys `mon`..`sun`, each `{open: 'HH:MM', close:
 * 'HH:MM'}` or `null` (null = closed that weekday). The model's saving
 * guard is the authority on the allowlist and the `HH:MM` shape — the
 * column itself stays untyped JSON so a future weekday-level exception
 * (a one-off holiday hour) can be added without a migration.
 *
 * `daily_capacity` is `unsignedInteger` and the model guards `>= 1`: a
 * policy with zero capacity would be a blackout of every day, which the
 * module models explicitly via `visitation_blackout_dates` instead.
 *
 * `cemetery_id` is unique — one policy per cemetery, the shape
 * `RequestVisitation`'s `where('cemetery_id', ...)->first()` depends on.
 * `restrictOnDelete` mirrors `grave_plots.cemetery_id`: the policy row
 * is an evidence-bearing record, never silently cascade-deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cemetery_visitation_policies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('cemetery_id')->constrained('cemeteries')->restrictOnDelete();
            $table->json('operating_hours');
            $table->unsignedInteger('daily_capacity');
            $table->timestamps();
            $table->unique('cemetery_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cemetery_visitation_policies');
    }
};
