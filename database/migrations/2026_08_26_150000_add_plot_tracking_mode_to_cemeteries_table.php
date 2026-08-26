<?php

declare(strict_types=1);

use App\Domain\CemeteryDirectory\PlotTrackingMode;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds `plot_tracking_mode` to `cemeteries` —
 * `docs/superpowers/plans/2026-08-26-cemetery-plot-tracking-mode.md` §"Cemetery
 * tracking-tier concept". Defaults every existing and future cemetery to
 * `aggregate` (`App\Domain\CemeteryDirectory\PlotTrackingMode::AGGREGATE`)
 * — no cemetery has any `CemeteryBlock` rows yet, so this matches current
 * reality rather than guessing. A cemetery only becomes `granular` via
 * `App\Domain\CemeteryDirectory\Actions\SetCemeteryPlotTrackingMode`, an
 * explicit admin decision (see that action's own doc block), never
 * inferred from data.
 *
 * Plain `string(16)` column, application-layer validated by
 * `PlotTrackingMode::assertKnown()` — this codebase's established
 * convention for every closed-list string column, not a Postgres enum
 * type (`cemeteries.type`, `grave_plots.plot_state` do the same).
 *
 * Column order is not controlled here: `Blueprint::after()` is a
 * MySQL-only modifier, a silent no-op on this app's PostgreSQL, so the
 * column simply lands at the end of the table. That is not semantically
 * meaningful — nothing depends on physical column position.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cemeteries', function (Blueprint $table) {
            $table->string('plot_tracking_mode', 16)
                ->default(PlotTrackingMode::AGGREGATE);
        });
    }

    public function down(): void
    {
        Schema::table('cemeteries', function (Blueprint $table) {
            $table->dropColumn('plot_tracking_mode');
        });
    }
};
