<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Removes the `plot_reservations_active_hold` partial unique index —
 * the fix round's rejection of the index-as-backstop design for
 * "one active hold per plot" (see the class doc block of
 * `App\Domain\PlotReservation\Actions\ReservePlot` for the corrected
 * mechanism and the plan/spec docs for the recorded alternative).
 *
 * The partial unique index on `(plot_id) WHERE state = 'held'` never
 * releases: `plot_reservations` rows are append-only and `state` never
 * mutates, so the ORIGINAL `held` row keeps its index entry forever and
 * any later re-reservation of the same plot (after release or expire
 * flipped it back to `available`) violates the index — a plot could
 * only ever be reserved once. The invariant now lives in the mechanism
 * that CAN release: the plot-row `lockForUpdate()` serialization (every
 * reservation action locks the plot row first) plus the plot's
 * `plot_state` aggregate (`available`/`reserved`) asserted under that
 * lock, with order-level idempotency via `activeForOrder()`.
 *
 * `down()` recreates the index exactly as
 * `2026_08_16_100020_create_plot_reservations_table.php`'s `up()`
 * created it, so rolling back restores the pre-fix schema.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DROP INDEX IF EXISTS plot_reservations_active_hold');
    }

    public function down(): void
    {
        DB::statement(
            'CREATE UNIQUE INDEX plot_reservations_active_hold '.
            'ON plot_reservations (plot_id) '.
            "WHERE state = 'held'"
        );
    }
};
