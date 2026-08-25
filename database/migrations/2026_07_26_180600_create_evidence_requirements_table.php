<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `evidence_requirements` — `design.md`'s Entities line. Backs
 * requirements.md AC6: "WHEN a package is marked complete THE SYSTEM SHALL
 * check fulfillment evidence for every required item." A package item's
 * `evidence_required` boolean (see `service_package_items`' own doc block)
 * says WHETHER an item needs evidence; this table describes WHAT that
 * evidence is — one item may need more than one piece (e.g. a photo AND a
 * signed handover form), hence a separate one-to-many table rather than a
 * single text column on the item.
 *
 * `description` is deliberately free text, not a closed-list constant
 * class: no cited document (`service-catalog.md`, this spec's
 * `requirements.md`/`design.md`) enumerates a fixed taxonomy of evidence
 * kinds (e.g. "photo" vs. "signed form" vs. "GPS check-in") — inventing one
 * here would be exactly the fabricated business data this batch's brief
 * forbids. An admin describes what is actually needed in plain language;
 * `is_required` (default `true`) exists for the rarer case of a
 * recommended-but-not-blocking evidence item — AC6's "required item" is the
 * common path this defaults to.
 *
 * `service_package_item_id` uses `cascadeOnDelete()` — same reasoning as
 * `substitution_policies.service_package_item_id`.
 *
 * Migration timestamp slot: see
 * `2026_07_26_180000_create_service_definitions_table.php`'s own doc block.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('evidence_requirements', function (Blueprint $table) {
            $table->id();

            $table->foreignId('service_package_item_id')
                ->constrained('service_package_items')
                ->cascadeOnDelete();

            $table->text('description');
            $table->boolean('is_required')->default(true);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evidence_requirements');
    }
};
