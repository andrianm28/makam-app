<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-0042 — a third quote-line family, PLOT.
 *
 * `grave_plot_id` names what was sold; `cemetery_package_id` names the pricing
 * vehicle that produced the amount. BOTH are written, and the CHECK below
 * makes that structural rather than habitual.
 *
 * Recording both is the point. A quote is a frozen snapshot: deriving the
 * package from the plot at read time is the defect, because
 * `grave_plots.cemetery_package_id` is `nullOnDelete` and its own migration
 * calls it "an indicative convenience reference, not the plot's identity". A
 * derived read after that link moves returns a price version that was never
 * charged.
 *
 * Both are `restrictOnDelete`, matching `price_version_id` and the two
 * existing family references: a row a quote points at may not be deleted out
 * from under it.
 *
 * Expand/contract, exactly like
 * `2026_08_15_100000_add_service_definition_id_to_quote_lines_table.php`
 * before it: new columns are nullable, no existing row is touched, and the
 * two existing families keep populating what they always did.
 *
 * Verified against live data before writing: all 101 `quote_lines` rows on
 * beta satisfy the SERVICE arm and none violates any arm, so the constraint
 * applies without a data fix.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quote_lines', function (Blueprint $table): void {
            // `foreignUuid`, not `foreignId`: `grave_plots.id` is a UUID
            // (`2026_08_16_100010_create_grave_plots_table.php`), same as
            // `quotes.id`/`quote_lines.id` above. `cemetery_packages.id`
            // below is the ordinary bigint `$table->id()`.
            $table->foreignUuid('grave_plot_id')
                ->nullable()
                ->after('service_definition_id')
                ->constrained('grave_plots')
                ->restrictOnDelete();

            $table->foreignId('cemetery_package_id')
                ->nullable()
                ->after('grave_plot_id')
                ->constrained('cemetery_packages')
                ->restrictOnDelete();
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // One arm per family. The PLOT arm requires BOTH columns together:
        // a half-formed plot line cannot be written at all, by anything.
        DB::statement(
            'ALTER TABLE quote_lines ADD CONSTRAINT quote_lines_line_family_check CHECK ('
            .'(service_package_version_id IS NOT NULL AND service_definition_id IS NULL '
            .'AND grave_plot_id IS NULL AND cemetery_package_id IS NULL) OR '
            .'(service_definition_id IS NOT NULL AND service_package_version_id IS NULL '
            .'AND grave_plot_id IS NULL AND cemetery_package_id IS NULL) OR '
            .'(grave_plot_id IS NOT NULL AND cemetery_package_id IS NOT NULL '
            .'AND service_package_version_id IS NULL AND service_definition_id IS NULL))'
        );
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE quote_lines DROP CONSTRAINT IF EXISTS quote_lines_line_family_check');
        }

        Schema::table('quote_lines', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('cemetery_package_id');
            $table->dropConstrainedForeignId('grave_plot_id');
        });
    }
};
