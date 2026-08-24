<?php

declare(strict_types=1);

use App\Domain\CemeteryDirectory\CemeteryPublicationStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `cemeteries` — `.kiro/specs/cemetery-directory-and-availability/design.md`
 * Data section names this table explicitly. Backs requirements.md AC1–AC3
 * and AC11 (launch cities, type/name/photo/address/maps/facilities/price
 * fields, city/type filtering).
 *
 * Sprint 4 task S4-T1 — master data/seeds only (`docs/planning/
 * sprint-plan.md` line 621). The full capability-resolver + public
 * projection + admin CMS batch (S4-T6, `cemetery-directory-and-availability`
 * AC1–AC12) is out of this migration's scope; this table and its seed data
 * exist so that later batch has real master data to resolve against.
 *
 * ---------------------------------------------------------------------------
 * `id` is a UUID primary key — the one deliberate deviation from the
 * `public-faq` batch's otherwise-matched auto-increment convention
 * ---------------------------------------------------------------------------
 * `docs/contracts/openapi.yaml`'s `CemeteryId` path parameter and
 * `CemeterySummary.id` schema both fix `format: uuid` for this exact
 * resource — checked directly against the contract, not assumed. Every
 * other domain-facing resource id in that same contract (`DraftId`,
 * `OrderId`, `CaseId`, `PlotId`, `ReservationId`) is uuid-typed too, so
 * this is the established contract-wide shape, not a one-off. `2026_07_26_
 * 130000_create_scope_assignments_table.php`'s own doc block left
 * `entity_id` as a bare string specifically because "no real domain model
 * exists yet whose primary key type this batch could commit to" — this
 * migration is the first to actually commit, in the direction the contract
 * already named. `App\Domain\CemeteryDirectory\Models\Cemetery` uses
 * Eloquent's `HasUuids` trait; child tables owned by the `CemeteryCapability`
 * module (`cemetery_capability_profiles`, `cemetery_packages`) reference
 * `cemetery_id` as `foreignUuid`, everything else in this batch keeps the
 * established auto-increment convention.
 *
 * ---------------------------------------------------------------------------
 * Column shape
 * ---------------------------------------------------------------------------
 * - `type` — `App\Domain\CemeteryDirectory\CemeteryType::KNOWN_TYPES`
 *   (`TPU`/`TPS`), plain string, application-layer-validated — this
 *   codebase's established convention for closed-list columns.
 * - `publication_status` — `CemeteryPublicationStatus::KNOWN_STATUSES`,
 *   backs AC2's "published TPU/TPS" filter. Defaults to `draft`; this
 *   migration's own seed data (a later migration in this same slot)
 *   explicitly publishes the example rows.
 * - `city` — `App\Domain\CemeteryDirectory\LaunchCityCode::KNOWN_CODES`,
 *   backs AC1/AC2. See that class's own doc block for its source
 *   (`docs/product/booking-wizard-fields.md`) and a flagged future
 *   duplication risk with the `Booking` module.
 * - `latitude`/`longitude`/`google_maps_url` are all nullable — AC11: "WHEN
 *   the map provider fails THE SYSTEM SHALL NOT block viewing of the
 *   textual address." `address` (required) is the only field a detail view
 *   may depend on unconditionally.
 * - `primary_photo_path` is nullable and stores a reference (storage path),
 *   not a public URL — no photo asset exists for any seeded row in this
 *   batch (see the seed migration's own doc block for why none is
 *   fabricated).
 * - `facilities` is a plain `json` array of free-text labels, not a closed
 *   list — `design.md`/`requirements.md` describe facilities as
 *   per-cemetery content an admin manages (AC10), not a fixed catalogue
 *   this module owns.
 * - `price_min`/`price_max`/`price_currency`/`price_source`/
 *   `price_effective_at` — AC3: "attributed price range." All nullable
 *   except `price_currency` (defaults `IDR`): a cemetery legitimately may
 *   have no price data yet, and showing nothing is honest; showing a
 *   number with no `price_source` would not be (`AGENTS.md` §Documentation
 *   / this project's general anti-fabrication discipline, the same
 *   reasoning `price_versions`' own migration — a sibling Sprint 4 batch —
 *   independently applied to `ServiceCatalog`: "Seeding an invented price
 *   here would be exactly the fabricated business data this batch's brief
 *   forbids"). Enforced at the application layer (`Cemetery::booted()`
 *   does not currently enforce this pairing — left as a note for whichever
 *   batch adds the admin write path, since no seed row in this batch sets
 *   a price at all).
 * - `operator_name` is a plain nullable string (owner/operator
 *   attribution) — distinct from `scope_assignments` (`entity_type =
 *   'cemetery'`), which is about WHICH ACTOR may edit this record, not
 *   what the record itself says about who owns/operates the physical
 *   cemetery. Both can exist without duplication; this batch does not
 *   create any `scope_assignments` rows (no real operator actors exist to
 *   assign to yet).
 * - `published_at`/`unpublished_at` mirror `faq_articles`' identical pair.
 *
 * Migration timestamp slot: `2026_07_26_190000`–`2026_07_26_199999` — the
 * next free range after this same day's `ServiceCatalog`/`Marketplace`
 * Sprint 4 batches, which used up to `2026_07_26_180700` (verified against
 * the actual migrations directory at the time this batch started, not
 * assumed from `docs/planning/agent-execution-plan.md`'s earlier
 * draft slot numbers, which predate and do not match what those two
 * batches actually used).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cemeteries', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->string('type', 8);
            $table->string('publication_status', 16)->default(CemeteryPublicationStatus::DEFAULT);

            $table->string('name');
            $table->string('slug')->unique();

            $table->string('city', 32);
            $table->text('address');

            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->string('google_maps_url')->nullable();

            $table->string('primary_photo_path')->nullable();

            $table->json('facilities')->nullable();

            $table->decimal('price_min', 14, 2)->nullable();
            $table->decimal('price_max', 14, 2)->nullable();
            $table->string('price_currency', 3)->default('IDR');
            $table->string('price_source')->nullable();
            $table->timestamp('price_effective_at')->nullable();

            $table->string('operator_name')->nullable();

            $table->timestamp('published_at')->nullable();
            $table->timestamp('unpublished_at')->nullable();

            $table->timestamps();

            // AC2's primary access path: "published cemeteries in this
            // city" — see Cemetery::scopePublished()/::scopeInCity().
            $table->index(['city', 'publication_status'], 'cemeteries_city_status_idx');

            // /pemakaman?type= filter (openapi.yaml).
            $table->index('type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cemeteries');
    }
};
