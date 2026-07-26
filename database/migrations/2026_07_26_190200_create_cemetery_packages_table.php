<?php

declare(strict_types=1);

use App\Domain\CemeteryCapability\CemeteryPackageAvailabilityStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `cemetery_packages` — the package/class-level availability table
 * `design.md`'s Data section names (`cemetery_packages / cemetery_classes`
 * — see `App\Domain\CemeteryCapability\Models\CemeteryPackage`'s own
 * class-level doc block for why this batch collapsed that pair into ONE
 * table rather than two, a documented judgement call). Backs requirements.
 * md AC6: "THE SYSTEM SHALL present Makam Tumpang availability explicitly
 * at the location/package/class level."
 *
 * Explicitly NOT the `blocks`/`plot_units`/`plot_status_events` tables —
 * those are normatively owned by `plot-inventory-and-reservation`
 * (`design.md`'s own "Table ownership" note) and are not created anywhere
 * in this batch. This table only records, per cemetery, which named
 * package/class offerings exist and their INDICATIVE availability status —
 * never a specific plot, never a guarantee.
 *
 * ---------------------------------------------------------------------------
 * Column shape
 * ---------------------------------------------------------------------------
 * - `cemetery_id` — `foreignUuid` against `cemeteries.id`,
 *   `cascadeOnDelete()`.
 * - `name` — plain string, e.g. "Makam Tumpang", "Makam Single". NOT a
 *   closed-list enum this migration/module owns — see `CemeteryPackage`'s
 *   own doc block for why (the canonical closed service-type list already
 *   belongs to `ServiceCatalog`/`Booking`, neither of which this batch may
 *   touch or duplicate).
 * - `class_label` — nullable string, e.g. "Kelas A". `NULL` means this row
 *   is package-level with no further class breakdown.
 * - `availability_status` —
 *   `App\Domain\CemeteryCapability\CemeteryPackageAvailabilityStatus::
 *   KNOWN_STATUSES`, plain string, application-layer-validated. Always
 *   indicative by construction — see that class's own doc block.
 * - `sort_order`/`is_active` — cheap, standard display-stability and
 *   soft-disable columns, mirroring `faq_articles.sort_order` — not a full
 *   admin lifecycle (this batch builds no admin write action for this
 *   table; that is S4-T6/`admin-operations` territory per AC10).
 *
 * Migration timestamp slot: see
 * `2026_07_26_190000_create_cemeteries_table.php`'s own doc block.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cemetery_packages', function (Blueprint $table) {
            $table->id();

            $table->foreignUuid('cemetery_id')->constrained('cemeteries')->cascadeOnDelete();

            $table->string('name');
            $table->string('class_label')->nullable();

            $table->string('availability_status', 32)->default(CemeteryPackageAvailabilityStatus::DEFAULT);

            $table->text('description')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->index(['cemetery_id', 'is_active'], 'cemetery_packages_cemetery_active_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cemetery_packages');
    }
};
