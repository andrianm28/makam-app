<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `service_definitions` — `.kiro/specs/package-and-service-bundles/
 * design.md`'s Entities line names this table explicitly. Backs
 * `docs/product/service-catalog.md`'s two catalogue tables (2 "Basic
 * services" + 10 "Additional services" = 12 codes, counted directly
 * against that file's own rows) and that document's
 * "Catalog rules" section, which this table's columns exist to satisfy:
 * "Admin manages definition, price, provider, area, schedule, and
 * availability without deployment" (the reason every one of these columns
 * is a plain admin-editable value, never a hardcoded constant anywhere
 * else) and "Every service declares fulfillment owner: platform, cemetery
 * operator, or vendor."
 *
 * ---------------------------------------------------------------------------
 * Column shape
 * ---------------------------------------------------------------------------
 * - `code` is the exact catalogue code (`DOCUMENT_PROCESSING`,
 *   `GRAVE_DIGGING`, `AMBULANCE`, ...) — plain string,
 *   application-layer-validated against
 *   `App\Domain\ServiceCatalog\ServiceCode::KNOWN_CODES`, not a Postgres
 *   enum — same established convention as every other closed-list string
 *   column in this codebase (see that class's own doc block).
 * - `name` is the exact Indonesian label `service-catalog.md` gives each
 *   code (e.g. "Pengurusan Dokumen").
 * - `category` is `basic` | `additional` (`App\Domain\ServiceCatalog\
 *   ServiceCategory`) — which of `service-catalog.md`'s two tables the code
 *   belongs to.
 * - `fulfillment_owner` is `platform` | `cemetery_operator` | `vendor`
 *   (`App\Domain\ServiceCatalog\FulfillmentOwner`), NULLABLE. See the seed
 *   migration's own doc block for why this is left unset at seed time
 *   rather than guessed per code.
 * - `requires_schedule` / `requires_manual_confirmation` are plain
 *   booleans, default `false`. `service-catalog.md`'s "Catalog rules"
 *   describe these as platform CAPABILITIES ("services requiring schedule
 *   expose a date/time window", "sensitive services may require manual
 *   confirmation") without naming which of the 12 codes they apply to — so
 *   the columns exist (the capability is real) but no code is seeded `true`
 *   for either (see the seed migration's own doc block — same reasoning as
 *   `fulfillment_owner`).
 * - `is_active` lets an admin temporarily withdraw a service definition
 *   from new authoring without deleting catalogue history — default
 *   `true`.
 * - `description` is a free-text admin field, nullable, no seed content
 *   (`service-catalog.md` gives no description copy beyond the code/label
 *   pair).
 *
 * No admin write-action to create/delete a `service_definitions` row exists
 * in this batch — like `App\Domain\Faq\Models\FaqCategory`, these 12 rows
 * are catalogue-defined master data, not free-form admin content; only the
 * catalogue-derived columns above (owner/flags/active/description) are
 * expected to be admin-edited in place, by a later batch's Filament
 * resource (explicitly out of this batch's scope — see `app/Domain/
 * ServiceCatalog/README` batch brief: no `app/Filament/**` changes here).
 *
 * Migration timestamp slot: `2026_07_26_180000` through `2026_07_26_189999`
 * — the next free range after Sprint 3/4's FAQ batch, which used up to
 * `2026_07_26_170400`. Reserved for `ServiceCatalog`
 * (`package-and-service-bundles`) only; sibling S4-T1 batches building
 * `CemeteryDirectory`/`CemeteryCapability` and `Marketplace` master data use
 * their own separate migration files/names, checked against this table
 * ownership at write time to avoid a collision (`app/Domain/README.md`:
 * "A module owns its own tables").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_definitions', function (Blueprint $table) {
            $table->id();

            $table->string('code', 64)->unique();
            $table->string('name');
            $table->string('category', 16);

            $table->string('fulfillment_owner', 24)->nullable();

            $table->boolean('requires_schedule')->default(false);
            $table->boolean('requires_manual_confirmation')->default(false);
            $table->boolean('is_active')->default(true);

            $table->text('description')->nullable();

            $table->timestamps();

            $table->index('category');
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_definitions');
    }
};
