<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `feature_flags` — "server-side flags per feature-flag-registry.md"
 * (design.md). The 18 flag rows are seeded by
 * `2026_07_26_120400_seed_feature_gate_registry.php`; this migration only
 * creates the shape.
 *
 * `flag_key` (e.g. `feature.online_payment`) is the natural key, matching
 * the literal strings listed in feature-flag-registry.md's `Flag` column.
 *
 * `prerequisite_gate_id` is nullable and only ever set when the registry
 * names exactly one `G-*` gate as the flag's prerequisite. Two rows in the
 * source registry do not fit that shape:
 *   - `feature.preneed_interest`'s prerequisite is "Approved interest flow"
 *     — not a gate id at all.
 *   - `feature.plot_inventory`'s prerequisite is "G-CAP-01/G-PLOT-01" — two
 *     gate ids, not one.
 * Both cases are carried in `prerequisite_note` (the registry's own text,
 * verbatim) instead of forcing a single FK that would misrepresent the
 * source document. See the seed migration for the exact per-row mapping.
 *
 * Migration timestamp slot: 2026_07_26_120000–2026_07_26_129999.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feature_flags', function (Blueprint $table) {
            $table->string('flag_key', 64)->primary();

            $table->boolean('default_enabled')->default(false);

            $table->string('owner')->nullable();

            $table->string('prerequisite_gate_id', 32)->nullable();
            $table->foreign('prerequisite_gate_id')
                ->references('gate_id')->on('feature_gates')
                ->nullOnDelete();

            // Registry's raw "Prerequisite" text for the two rows that do
            // not reduce to a single gate id — see class doc block.
            $table->string('prerequisite_note')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feature_flags');
    }
};
