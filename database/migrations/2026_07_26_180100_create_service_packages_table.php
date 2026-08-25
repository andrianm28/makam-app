<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `service_packages` — `.kiro/specs/package-and-service-bundles/design.md`'s
 * Entities line names this table explicitly. A package is a stable, named
 * bundle identity (e.g. "Paket Standar"); its actual content — included/
 * optional/excluded items, quantities, fulfillment owners — lives on its
 * versions (`service_package_versions` / `service_package_items`), never on
 * this row. `design.md`: "A package expander creates quote lines and
 * fulfillment intents. Later changes create new versions, never mutate
 * accepted quote contents."
 *
 * ---------------------------------------------------------------------------
 * No seed data — a deliberate scope boundary for S4-T1
 * ---------------------------------------------------------------------------
 * `docs/product/service-catalog.md` (this repo's one canonical catalogue
 * this batch's brief authorizes seeding from) defines 12 individual
 * SERVICE codes, not any named PACKAGE/bundle. No other cited document
 * (`.kiro/specs/package-and-service-bundles/requirements.md`, `design.md`)
 * names an example package either. This batch therefore ships the schema
 * and the lifecycle Actions that operate on it correctly
 * (`Actions\DefineServicePackage`, `Actions\PublishServicePackageVersion`,
 * `Actions\ReviseServicePackageVersion`) but seeds zero rows here — inventing
 * a package (its name, contents, pricing) would be business data this
 * batch's brief explicitly forbids fabricating. A later batch's admin
 * Filament resource is where real packages get authored.
 *
 * `code` is a plain admin-chosen unique identifier (not a closed-list
 * catalogue constant like `service_definitions.code` — there is no
 * canonical package list to close it against).
 *
 * Migration timestamp slot: see
 * `2026_07_26_180000_create_service_definitions_table.php`'s own doc block.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_packages', function (Blueprint $table) {
            $table->id();

            $table->string('code', 64)->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_packages');
    }
};
