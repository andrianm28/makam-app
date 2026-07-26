<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `price_versions` — `design.md`'s Entities line. Backs `service-catalog.md`
 * "Catalog rules": "Price is versioned and snapshot into quote/order" and
 * requirements.md AC3 ("WHEN a quote is generated THE SYSTEM SHALL
 * reference the exact package, service, and price versions selected").
 *
 * ---------------------------------------------------------------------------
 * Polymorphic on purpose — one price-versioning mechanism, two priceable
 * kinds (a deliberate judgement call, not something either cited document
 * spells out column-by-column)
 * ---------------------------------------------------------------------------
 * `design.md` names exactly one `price_versions` entity, not two — it does
 * not say whether prices are versioned per `service_definitions` row, per
 * `service_package_versions` row, or both. `service-catalog.md`'s own
 * "Catalog rules" only says "price is versioned" in the context of the
 * SERVICE catalogue; nothing rules out a package needing its own bundled
 * price too, and duplicating this exact table shape twice (once per
 * priceable kind) would itself be the kind of hand-maintained duplication
 * `AGENTS.md` §Documentation warns against for CONTENT — the same
 * reasoning extends to duplicating a whole table definition for a
 * mechanism, not just catalogue content. Standard Eloquent `morphTo`
 * (`priceable_type` + `priceable_id`) lets both `Models\ServiceDefinition`
 * and `Models\ServicePackageVersion` version their own price through the
 * same table/model/Action
 * (`Actions\RecordServiceDefinitionPriceVersion` today; a package-priceable
 * caller can reuse `Models\PriceVersion` directly the same way).
 *
 * ---------------------------------------------------------------------------
 * No seed data — same reasoning as `service_packages`
 * ---------------------------------------------------------------------------
 * `service-catalog.md` gives a code and an Indonesian label per service,
 * never a Rupiah amount. Seeding an invented price here would be exactly
 * the fabricated business data this batch's brief forbids. This table
 * ships empty; `tests/Feature/Domain/ServiceCatalog/PriceVersioningTest.php`
 * exercises the versioning behaviour with synthetic test-only amounts, not
 * real seed content.
 *
 * ---------------------------------------------------------------------------
 * Append-only shape — mirrors `faq_article_versions` / `AuditEvent` /
 * `MfaChallenge` / `ReauthenticationEvent`
 * ---------------------------------------------------------------------------
 * No `created_at`/`updated_at` — `effective_from` is the explicit,
 * server-set "when this version became current" column, and a row is never
 * updated after insert except to stamp `superseded_at` when a newer version
 * for the same priceable is recorded (`Actions\
 * RecordServiceDefinitionPriceVersion` does both in one transaction). A
 * `null` `superseded_at` means "this is the current version for this
 * priceable" — never delete or renumber a version after the fact.
 *
 * Migration timestamp slot: see
 * `2026_07_26_180000_create_service_definitions_table.php`'s own doc block.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('price_versions', function (Blueprint $table) {
            $table->id();

            $table->string('priceable_type');
            $table->unsignedBigInteger('priceable_id');

            $table->unsignedInteger('version_number');

            $table->decimal('amount', 12, 2);
            $table->string('currency', 3)->default('IDR');

            $table->string('source')->nullable();

            $table->timestamp('effective_from');
            $table->timestamp('superseded_at')->nullable();

            $table->string('recorded_by')->nullable();

            $table->unique(
                ['priceable_type', 'priceable_id', 'version_number'],
                'price_versions_priceable_version_unique'
            );
            $table->index(['priceable_type', 'priceable_id', 'superseded_at'], 'price_versions_priceable_current_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('price_versions');
    }
};
