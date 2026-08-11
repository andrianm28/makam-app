<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `renewal_external_markings` — `.kiro/specs/renewal-and-grave-registry/
 * design.md`'s Data section, backing AC10: "Admin/operator SHALL be able to
 * mark a renewal as paid externally, with evidence." L8 Task 1
 * (`docs/superpowers/plans/2026-08-12-platform-renewal-completion.md`)
 * builds the schema; `Actions\MarkExternalRenewal`, the only intended
 * writer, is Task 7 in this same lane and does not exist yet — it is
 * explicitly human-sign-off territory per that plan's Ruling B (who may
 * mark an external renewal) and is not started by this task.
 *
 * Migration timestamp slot: `2026_08_12_100000`-`2026_08_12_100029`, the
 * same batch as `2026_08_12_100000_create_renewals_table.php` — see that
 * migration's doc block for why the slot is recorded.
 *
 * ---------------------------------------------------------------------------
 * This table does NOT carry the AC11 uniqueness guard
 * ---------------------------------------------------------------------------
 * Read `2026_08_12_100000_create_renewals_table.php`'s doc block before
 * changing anything here: `renewals_grave_period_unique` sits on the parent
 * `renewals` table, across BOTH `source` values, precisely so this table
 * does not need a competing uniqueness rule of its own. A row here only
 * exists once its parent `renewals` row (already carrying
 * `source = RenewalSource::EXTERNAL`) has survived that constraint. Adding
 * a second uniqueness check on this table would be redundant at best and
 * a second, out-of-sync source of truth at worst — exactly what
 * `design.md`'s "share the same uniqueness domain" phrase warns against.
 *
 * ---------------------------------------------------------------------------
 * Column shape and the judgement calls behind it
 * ---------------------------------------------------------------------------
 * - `id` is a UUID, matching `renewals` and `renewal_quotes`.
 *
 * - `renewal_id` is `restrictOnDelete`, the same reasoning as the other two
 *   tables in this batch: this row is AC10's evidence trail, and a renewal
 *   being deleted must not silently take that evidence with it.
 *
 * - `marked_by_actor_ref` is an opaque reference, not a raw actor identity
 *   column — `App\Domain\GraveRegistry\Models\GraveRecord`'s
 *   `heir_contact_reference` set the "reference, not raw PII" precedent
 *   this follows; the real actor identity resolves through
 *   `App\Platform\IdentityAccess`.
 *
 * - `evidence_reference` and `reason` are both required `string` columns —
 *   AC10 names evidence as part of what "mark as paid externally" means,
 *   not an optional extra, and `RenewalMarkingPolicy` (Task 7) is expected
 *   to require a non-blank reason for this sensitive action per `AGENTS.md`
 *   §Authorization and files' "sensitive → mandatory reason" pattern
 *   already established for `PAYMENT_MANUAL_VERIFICATION`.
 *
 * - `marked_at` is NOT nullable — unlike `renewals.settled_at` (which is
 *   null until a still-pending renewal settles), a row in THIS table only
 *   ever exists because a marking already happened; there is no
 *   in-between state to represent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('renewal_external_markings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('renewal_id')->constrained('renewals')->restrictOnDelete();
            $table->string('marked_by_actor_ref');
            $table->string('evidence_reference');
            $table->string('reason');
            $table->timestamp('marked_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('renewal_external_markings');
    }
};
