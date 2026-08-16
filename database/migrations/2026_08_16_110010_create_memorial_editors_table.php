<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `memorial_editors` — consent-gated family authority (AC1). One row per
 * grant; rows are MUTABLE (revoked_at flips a row from active to
 * inactive) — see the partial unique index note below.
 *
 * `consent_evidence_ref` references the platform document vault's
 * `documents.id` (a private reference, never the evidence's content).
 * `actor_id` is an identity reference (`ActorContext::$identityReference`)
 * for whichever identity backend the actor came from — int|string in the
 * application, stored as string for either shape.
 *
 * ---------------------------------------------------------------------------
 * `memorial_editors_active_editor` — partial unique on mutable rows
 * ---------------------------------------------------------------------------
 * `CREATE UNIQUE INDEX ... ON (memorial_profile_id, actor_id) WHERE
 * revoked_at IS NULL` enforces "one active editor per (profile, actor)".
 * Because rows MUTATE (revocation sets revoked_at instead of deleting),
 * the index entry releases on revoke — a revoked editor can be granted
 * again, unlike the append-only `plot_reservations` case where the old
 * `held` row permanently held its index slot (the failure mode
 * `2026_08_16_100030_drop_plot_reservations_active_hold_index.php`
 * documents). Both PostgreSQL and SQLite accept this exact partial-index
 * syntax, so it is written once and NOT guarded by driver.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('memorial_editors', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignUuid('memorial_profile_id')
                ->constrained('memorial_profiles')
                ->restrictOnDelete();

            // Identity reference of the granted family actor.
            $table->string('actor_id');

            // Vault document id of the consent evidence — REQUIRED by
            // `GrantMemorialEditor` (AC1); no grant without it.
            $table->string('consent_evidence_ref');

            $table->timestamp('granted_at')->nullable();
            $table->timestamp('revoked_at')->nullable();

            $table->timestamps();

            $table->index(['memorial_profile_id', 'actor_id']);
        });

        DB::statement(
            'CREATE UNIQUE INDEX memorial_editors_active_editor '.
            'ON memorial_editors (memorial_profile_id, actor_id) '.
            'WHERE revoked_at IS NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS memorial_editors_active_editor');
        Schema::dropIfExists('memorial_editors');
    }
};
