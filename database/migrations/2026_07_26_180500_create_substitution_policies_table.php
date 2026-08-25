<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `substitution_policies` — `design.md`'s Entities line. Backs
 * requirements.md AC5: "WHEN a fulfillment substitution occurs THE SYSTEM
 * SHALL apply the configured substitution rule and obtain customer approval
 * where required."
 *
 * One row declares that `substitute_service_definition_id` is an
 * admin-approved stand-in for the service on `service_package_item_id`
 * (e.g. a `HEARSE` item's policy naming an alternate vendor's equivalent
 * service as an acceptable substitute if the primary one becomes
 * unavailable) and whether swapping to it needs the customer to approve
 * first. This table is the CONFIGURATION AC5 refers to
 * ("the configured substitution rule") — actually detecting an
 * unavailability and executing/prompting for a swap at order time is
 * runtime behaviour for a later batch (`tasks.md`: "Implement substitution
 * and evidence rules" is its own separate, unchecked task line, distinct
 * from this batch's "Add package/version/item schema" line); this batch
 * ships the data model a later batch's substitution engine reads.
 *
 * `requires_customer_approval` defaults `true` — a conservative default:
 * given AC5's own "where required" phrasing implies approval is sometimes
 * skippable, but no cited document says which substitutions qualify for
 * the skip, defaulting to "ask the customer" is the safer failure mode
 * than silently substituting without consent. An admin authoring a policy
 * may explicitly set it `false` for equivalences considered inconsequential
 * (`Actions\DefineServicePackage`'s own doc block repeats this reasoning at
 * the call site).
 *
 * `service_package_item_id` uses `cascadeOnDelete()` — a substitution
 * policy has no meaning once its owning item is gone (same reasoning as
 * `service_package_versions.service_package_id`).
 * `substitute_service_definition_id` uses `restrictOnDelete()` — same
 * reasoning as `service_package_items.service_definition_id`.
 *
 * Migration timestamp slot: see
 * `2026_07_26_180000_create_service_definitions_table.php`'s own doc block.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('substitution_policies', function (Blueprint $table) {
            $table->id();

            $table->foreignId('service_package_item_id')
                ->constrained('service_package_items')
                ->cascadeOnDelete();

            $table->foreignId('substitute_service_definition_id')
                ->constrained('service_definitions')
                ->restrictOnDelete();

            $table->boolean('requires_customer_approval')->default(true);
            $table->text('reason')->nullable();

            $table->timestamps();

            $table->unique(
                ['service_package_item_id', 'substitute_service_definition_id'],
                'substitution_policies_item_substitute_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('substitution_policies');
    }
};
