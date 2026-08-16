<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `cemetery_blocks` — the plot-inventory reference-data parent
 * (`docs/superpowers/specs/2026-08-16-plot-inventory-reservation-design.md`
 * §4.1). One row per physical block of plots in a cemetery; the
 * individual plots are generated in bulk by
 * `App\Domain\PlotInventory\Actions\CreateCemeteryBlock` and live in
 * `grave_plots` (see `2026_08_16_100010_create_grave_plots_table.php`).
 *
 * ---------------------------------------------------------------------------
 * Column shape and the judgement calls behind it
 * ---------------------------------------------------------------------------
 * - `id` is a UUID — `docs/contracts/openapi.yaml` fixes `format: uuid` for
 *   every domain-facing resource id it names (`PlotId` included), the same
 *   contract-wide shape `cemeteries` already follows; `grave_plots`
 *   inherits it.
 *
 * - `cemetery_id` is `restrictOnDelete`, the same deliberate choice
 *   `grave_records` documents for its own parent link: a cemetery row
 *   being deleted must not silently take its plot inventory with it.
 *   Inventory is authoritative operational data, not derived state
 *   (`cemetery_capability_profiles` / `cemetery_packages` cascade because
 *   they ARE derived state — different shape, same rule).
 *
 * - `code` is unique per cemetery (not globally) and normalized to
 *   uppercase + trimmed by `CemeteryBlock::booted()` — an operator may
 *   enter "blok-a", the stored value is always `BLOK-A`.
 *
 * - `capacity` is `unsignedInteger` ≥ 1, enforced at the application
 *   layer (`CemeteryBlock::booted()`); `is_active` defaults true —
 *   inventory blocks are active unless an operator deactivates them.
 *
 * Migration timestamp slot: `2026_08_16_100000`-`2026_08_16_109999`,
 * assigned by the P3 plot-inventory plan's Task 1 brief. `grave_plots`
 * takes the next slot (`100010`) so the parent precedes its child.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cemetery_blocks', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignUuid('cemetery_id')->constrained('cemeteries')->restrictOnDelete();

            $table->string('code');
            $table->string('name');
            $table->unsignedInteger('capacity');
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->unique(['cemetery_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cemetery_blocks');
    }
};
