<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `service_package_items` — `design.md`'s Entities line. One row per
 * included/optional/excluded line in a package version — backs
 * requirements.md AC1 (every attribute that acceptance criterion lists is a
 * column here), AC4 ("WHEN a customer selects an optional item THE SYSTEM
 * SHALL add it as a separate accepted quote line" — this table is what a
 * later quote-expansion batch iterates), and AC7 ("package items fulfilled
 * by the cemetery, the platform, or a vendor").
 *
 * ---------------------------------------------------------------------------
 * Column shape, mapped directly to AC1's own list
 * ---------------------------------------------------------------------------
 * - "included, optional, and excluded items" -> `item_type`
 *   (`App\Domain\ServiceCatalog\ServicePackageItemType`).
 * - the item itself -> `service_definition_id`, a required reference into
 *   the 12-code catalogue (`restrictOnDelete()` — a `service_definitions`
 *   row is permanent catalogue master data, see that table's own doc
 *   block, so silently cascading a delete through here would be wrong).
 * - "quantities, units" -> `quantity` (unsigned int) / `unit` (free-text
 *   string, e.g. "unit", "hari" — `service-catalog.md` does not enumerate a
 *   closed unit list, so this is deliberately not a constant class).
 * - "fulfillment owner" -> `fulfillment_owner`
 *   (`App\Domain\ServiceCatalog\FulfillmentOwner`), required at authoring
 *   time. Deliberately NOT auto-copied from `service_definitions.
 *   fulfillment_owner` (which is nullable and unset at seed time — see that
 *   table's doc block) — an admin authoring a package item must declare it
 *   explicitly, which is also what lets one service (e.g. `HEARSE`) be
 *   platform-fulfilled in one package and vendor-fulfilled in another.
 * - "service area/window" -> `service_area` (free text, nullable) and
 *   `requires_schedule_window` (boolean, default `false`). Modelled as a
 *   REQUIREMENT FLAG, not a literal date/time pair: `service-catalog.md`'s
 *   "Catalog rules" wording — "services requiring schedule expose a
 *   date/time window" — describes a window the CUSTOMER picks at
 *   order/quote time, not a fixed date baked into catalogue master data. An
 *   actual scheduled window belongs to a future order/quote-line record,
 *   out of this batch's scope.
 * - "evidence" -> `evidence_required` (boolean, default `false`) plus the
 *   separate `evidence_requirements` table for the descriptive text of what
 *   evidence is expected (one item may need more than one piece of
 *   evidence) — see that migration's own doc block.
 *
 * `notes` is a free-text admin field with no catalogue-defined content.
 *
 * ---------------------------------------------------------------------------
 * AC2 enforcement (item side) — read before writing to this table directly
 * ---------------------------------------------------------------------------
 * See `service_package_versions`' own doc block for the split between the
 * version-side and item-side halves of the AC2 guarantee.
 * `Models\ServicePackageItem::booted()` refuses to insert, update, or
 * delete any row whose `service_package_version_id` points at an
 * already-`published` version. NEVER write to this table via anything
 * other than `Actions\DefineServicePackage` (draft authoring) or
 * `Actions\ReviseServicePackageVersion` (copying into a fresh draft) —
 * doing so risks either bypassing that guard via a raw query, or (if going
 * through the model) hitting the guard's exception unexpectedly.
 *
 * A package version's items are unique per `(version, service_definition,
 * item_type)` — the same service cannot appear twice with the same
 * inclusion type in one version (it MAY appear as both, e.g. one unit
 * `included` plus additional units `optional`, which is two distinct
 * `item_type` rows, not a duplicate).
 *
 * Migration timestamp slot: see
 * `2026_07_26_180000_create_service_definitions_table.php`'s own doc block.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_package_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('service_package_version_id')
                ->constrained('service_package_versions')
                ->cascadeOnDelete();

            $table->foreignId('service_definition_id')
                ->constrained('service_definitions')
                ->restrictOnDelete();

            $table->string('item_type', 16);

            $table->unsignedInteger('quantity');
            $table->string('unit', 32);

            $table->string('fulfillment_owner', 24);

            $table->string('service_area')->nullable();
            $table->boolean('requires_schedule_window')->default(false);

            $table->boolean('evidence_required')->default(false);

            $table->text('notes')->nullable();

            $table->timestamps();

            $table->unique(
                ['service_package_version_id', 'service_definition_id', 'item_type'],
                'service_package_items_version_service_type_unique'
            );
            $table->index(['service_package_version_id', 'item_type'], 'service_package_items_version_type_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_package_items');
    }
};
