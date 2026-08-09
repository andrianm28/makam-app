<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `service_package_versions` — `design.md`'s Entities line. Backs
 * requirements.md AC1 ("for each package version, the included, optional,
 * and excluded items, quantities, units, fulfillment owner, service
 * area/window, and evidence") and AC2 ("SHALL NOT allow modification of a
 * published package version").
 *
 * ---------------------------------------------------------------------------
 * `status` — two states, not three
 * ---------------------------------------------------------------------------
 * `draft` | `published` — plain string, application-layer-validated against
 * `App\Domain\ServiceCatalog\ServicePackageVersionStatus::KNOWN_STATUSES`,
 * with no Postgres enum and no CHECK constraint. This block previously
 * justified that with "same established convention as every other
 * closed-list string column in this codebase". That was false, and was
 * already false when written: `audit_events.outcome`
 * (`2026_07_26_110000_create_audit_events_table.php:190`) and
 * `outbox_events.classification`
 * (`2026_07_26_140000_create_outbox_events_table.php:204`) both ship real
 * pgsql-guarded CHECK constraints, and
 * `2026_07_26_200000_create_menu_interaction_events_table.php:111` names
 * that as the convention. The honest statement is: this column deliberately
 * relies on application-layer validation ALONE, which is a weaker choice
 * than two sibling tables make, and adding a CHECK constraint now is a
 * migration against a table deployed to `dev.makam.co.id` — ledgered as a
 * human-ruling item, not silently deferred. (Corrected 09 Aug 2026 by the
 * ServiceCatalog Superpowers retrofit, F10.) See
 * `ServicePackageVersionStatus`'s own doc block for why this is deliberately
 * two states, not `FaqArticlePublishState`'s three.
 *
 * ---------------------------------------------------------------------------
 * AC2 enforcement — where the freeze actually lives
 * ---------------------------------------------------------------------------
 * This migration only declares the column; the actual "a published version
 * can never be modified again" guarantee is enforced in
 * `Models\ServicePackageVersion::booted()` (blocks any `UPDATE` on a row
 * whose ORIGINAL, pre-save `status` was already `published` — the one
 * exception being the single draft-to-published transition itself, which
 * `Actions\PublishServicePackageVersion` performs) and mirrored in
 * `Models\ServicePackageItem::booted()` (blocks insert/update/delete of any
 * item belonging to an already-published version). See both models' own
 * class-level doc blocks for the full guarantee — the same "read this
 * before writing any query against this model" treatment
 * `App\Domain\Faq\Models\FaqArticle` gives its own AC6 guarantee.
 *
 * `version_number` starts at `1` and increments by exactly one per package,
 * assigned only by `Actions\DefineServicePackage` (the first version) and
 * `Actions\ReviseServicePackageVersion` (every version after) — never
 * user-supplied directly.
 *
 * `service_package_id` uses `cascadeOnDelete()`, unlike `faq_articles`'
 * `restrictOnDelete()` back to its (permanent, seed-protected) category:
 * packages are ordinary admin-authored records with no seed-data
 * protection, and a version has no independent existence once its owning
 * package is gone.
 *
 * Migration timestamp slot: see
 * `2026_07_26_180000_create_service_definitions_table.php`'s own doc block.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_package_versions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('service_package_id')
                ->constrained('service_packages')
                ->cascadeOnDelete();

            $table->unsignedInteger('version_number');
            $table->string('status', 16)->default('draft');
            $table->timestamp('published_at')->nullable();

            $table->timestamps();

            $table->unique(['service_package_id', 'version_number'], 'service_package_versions_package_version_unique');
            $table->index(['service_package_id', 'status'], 'service_package_versions_package_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_package_versions');
    }
};
