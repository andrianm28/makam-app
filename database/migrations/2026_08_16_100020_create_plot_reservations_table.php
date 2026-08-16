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
 * `plot_reservations_active_hold` — the load-bearing invariant of this
 * whole lane
 * ---------------------------------------------------------------------------
 * "One active hold per plot" as a DATABASE guarantee rather than an
 * application-level convention that a race condition (two concurrent
 * `ReservePlot` calls both passing the in-memory availability assert
 * before either commits) could silently violate. The application's
 * `lockForUpdate()` re-read serializes the common race; this index is
 * the backstop for the one narrow window the lock cannot close alone.
 *
 * Written with `DB::statement()`, unguarded by driver, because
 * Laravel's schema builder has no portable partial-index API and both
 * PostgreSQL and SQLite accept the identical
 * `CREATE UNIQUE INDEX ... WHERE ...` syntax — the same verified
 * approach as `order_status_events_paid_once`
 * (`2026_08_12_100010_create_order_status_events_table.php`), and this
 * migration runs against the hermetic SQLite suite as well as CI's
 * PostgreSQL 18. A constraint that exists in production but not in the
 * test suite is exactly the failure mode this index exists to prevent.
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
