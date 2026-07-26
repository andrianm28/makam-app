<?php

declare(strict_types=1);

use App\Domain\CemeteryCapability\AvailabilityMode;
use App\Domain\CemeteryCapability\BookingMode;
use App\Domain\CemeteryCapability\CertificateMode;
use App\Domain\CemeteryCapability\MapMode;
use App\Domain\CemeteryCapability\RegistryMode;
use App\Domain\CemeteryCapability\VisitationMode;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `cemetery_capability_profiles` — `design.md`'s Data section names this
 * table explicitly: `cemetery_capability_profiles(version, modes, source,
 * effective_at, evidence)`. Backs requirements.md AC4 ("THE SYSTEM SHALL
 * maintain an explicit versioned capability profile for every cemetery.
 * WHEN a capability profile is missing THE SYSTEM SHALL use safe
 * defaults"), AC5, and AC7. `docs/architecture/overview.md` §6 is the
 * authority for the six mode names/cases/safe-defaults this table stores;
 * see `App\Domain\CemeteryCapability\Models\CemeteryCapabilityProfile`'s
 * own class-level doc block for the full versioning-shape and
 * evidence-trail-scope reasoning (including the documented judgement call
 * on "one trail per version, not one per mode").
 *
 * ---------------------------------------------------------------------------
 * Append-only versioned shape — `superseded_at`, not `is_current`
 * ---------------------------------------------------------------------------
 * Mirrors `price_versions` (`ServiceCatalog`, this same Sprint 4 wave) and
 * `faq_article_versions` exactly: no `updated_at` (`$timestamps = false` on
 * the model), a nullable `superseded_at` where `NULL` means "this is the
 * current version," and a version is never updated or deleted after
 * insert — only superseded by inserting the next one. `effective_at` is
 * `design.md`'s own literal column name (not `effective_from`, which
 * `price_versions` happens to use) — this migration matches THIS spec's
 * own `design.md` wording rather than the sibling table's naming.
 *
 * No database-level constraint enforces "at most one row with
 * `superseded_at IS NULL` per `cemetery_id`" — same reasoning
 * `2026_07_26_130000_create_scope_assignments_table.php`'s own doc block
 * gives for skipping a cross-driver constraint this batch has not verified
 * safe on both the SQLite test driver and production PostgreSQL 18;
 * enforced at the application layer instead (`Actions\
 * ResolveCemeteryCapabilityProfile` reads via `scopeCurrent()`, and any
 * future write action must supersede-then-insert inside one transaction,
 * the same shape `PublishFaqArticle`/`RecordServiceDefinitionPriceVersion`
 * already establish).
 *
 * ---------------------------------------------------------------------------
 * Column shape
 * ---------------------------------------------------------------------------
 * - `cemetery_id` — `foreignUuid` against `cemeteries.id` (see that
 *   migration's own doc block for why `cemeteries` uses a UUID key),
 *   `cascadeOnDelete()`: a capability profile has no meaning without its
 *   owning cemetery.
 * - Six mode columns, each a plain string validated against its own
 *   closed-list class in `App\Domain\CemeteryCapability\*Mode` — same
 *   established convention as every other closed-list column in this
 *   codebase. Column defaults reference each class's own `DEFAULT`
 *   constant rather than restating the literal string, so the safe
 *   defaults are defined exactly once (`CemeteryCapabilityProfile::
 *   safeDefaults()` reads from the same source).
 * - `source`/`owner`/`evidence`/`rollback_plan` — the activation-evidence
 *   trail this batch's brief requires. `source` records who/what supplied
 *   this version (e.g. `seed:cemetery-directory-master-data`); `owner` is
 *   the accountable person/role; `evidence` and `rollback_plan` are free
 *   text. All nullable except `source`, since even a seed-generated safe
 *   default profile should say where it came from.
 *
 * Migration timestamp slot: see
 * `2026_07_26_190000_create_cemeteries_table.php`'s own doc block.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cemetery_capability_profiles', function (Blueprint $table) {
            $table->id();

            $table->foreignUuid('cemetery_id')->constrained('cemeteries')->cascadeOnDelete();

            $table->unsignedInteger('version_number');

            $table->string('availability_mode', 24)->default(AvailabilityMode::DEFAULT);
            $table->string('booking_mode', 24)->default(BookingMode::DEFAULT);
            $table->string('map_mode', 24)->default(MapMode::DEFAULT);
            $table->string('registry_mode', 24)->default(RegistryMode::DEFAULT);
            $table->string('certificate_mode', 24)->default(CertificateMode::DEFAULT);
            $table->string('visitation_mode', 24)->default(VisitationMode::DEFAULT);

            $table->string('source');
            $table->string('owner')->nullable();
            $table->text('evidence')->nullable();
            $table->text('rollback_plan')->nullable();

            $table->timestamp('effective_at');
            $table->timestamp('superseded_at')->nullable();

            $table->unique(['cemetery_id', 'version_number'], 'cemetery_capability_profiles_cemetery_version_unique');

            // Actions\ResolveCemeteryCapabilityProfile's primary access
            // path: "the current profile for this cemetery."
            $table->index(['cemetery_id', 'superseded_at'], 'cemetery_capability_profiles_cemetery_current_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cemetery_capability_profiles');
    }
};
