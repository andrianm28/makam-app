<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `plot_reservations` — Task 3 of the
 * `2026-08-16-p3-plot-inventory-reservation` plan. One row per
 * reservation-state transition (held/confirmed/released/expired —
 * `PlotReservationState`). Append-only: rows are inserted by
 * `App\Domain\PlotReservation\Actions\ReservePlot` and the lifecycle
 * actions, never updated or deleted (the model enforces this; there is
 * no second PostgreSQL role to REVOKE UPDATE/DELETE from).
 *
 * ---------------------------------------------------------------------------
 * `plot_reservations_active_hold` — created here, dropped by the fix
 * round
 * ---------------------------------------------------------------------------
 * This migration originally created a partial unique index
 * `plot_reservations_active_hold` on `(plot_id) WHERE state = 'held'`
 * as the database backstop for "one active hold per plot". The fix
 * round proved the design broken: `plot_reservations` rows are
 * append-only and `state` never mutates, so the ORIGINAL `held` row
 * keeps its index entry forever and a plot that was ever held could
 * never be held again. The index is dropped by
 * `2026_08_16_100030_drop_plot_reservations_active_hold_index.php`
 * (which recreates it in `down()`); the invariant is enforced by the
 * plot-row `lockForUpdate()` + `plot_state` aggregate — see
 * `Actions\ReservePlot`'s class doc block and the plan's Global
 * Constraints.
 *
 * The `DB::statement()` form is kept here exactly as shipped so the
 * migration history matches what every environment ran; it is written
 * unguarded by driver because both PostgreSQL and SQLite accept the
 * identical `CREATE UNIQUE INDEX ... WHERE ...` syntax.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plot_reservations', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // `restrictOnDelete()`, NOT `cascadeOnDelete()` — the same
            // choice as `order_status_events.order_id`. A cascade would
            // let one `DELETE FROM grave_plots` destroy the reservation
            // evidence AND release `plot_reservations_active_hold` for
            // that plot, silently making a once-held plot holdable again.
            // `GravePlot`'s delete-blocked guard (Task 1) refuses at the
            // model layer for the same reason.
            $table->foreignUuid('plot_id')->constrained('grave_plots')->restrictOnDelete();

            // Nullable: a reservation is keyed to its plot; an order is
            // present on the operator-initiated path but the lifecycle is
            // plot-scoped. `restrictOnDelete` for the same evidence-
            // preservation reason as `plot_id`.
            $table->foreignUuid('order_id')->nullable()->constrained('orders')->restrictOnDelete();

            $table->string('state', 32);
            $table->string('reserved_by_ref');
            $table->text('reason')->nullable();

            $table->timestamp('reserved_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->timestamp('expired_at')->nullable();

            $table->timestamps();

            $table->index(['plot_id', 'state']);
        });

        // The exactly-once index — see class doc block. Both PostgreSQL
        // and SQLite support partial indexes with this exact syntax, so
        // this is written once and NOT guarded by driver.
        DB::statement(
            'CREATE UNIQUE INDEX plot_reservations_active_hold '.
            'ON plot_reservations (plot_id) '.
            "WHERE state = 'held'"
        );
    }

    public function down(): void
    {
        // Dropped before the table — on PostgreSQL the index dies with
        // the table anyway; on SQLite the explicit drop is the portable
        // form (`IF EXISTS` keeps it a no-op where the table is already
        // gone).
        DB::statement('DROP INDEX IF EXISTS plot_reservations_active_hold');

        Schema::dropIfExists('plot_reservations');
    }
};
