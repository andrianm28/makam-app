<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `actor_role_assignments` — the table `App\Platform\IdentityAccess\Roles
 * \ActorRoleReader` (a later task in this same lane) reads to resolve
 * `ActorContext::$roles`. Lane L5
 * (`docs/superpowers/plans/2026-08-11-platform-identity-seam.md`), Task 1.
 * Design doc:
 * `docs/superpowers/specs/2026-08-11-platform-identity-seam-design.md`,
 * decisions 2 and 3.
 *
 * Named to parallel `scope_assignments` exactly
 * (`2026_07_26_130000_create_scope_assignments_table.php`) — same shape,
 * same reasoning, deliberately. See that migration and
 * `Roles\Models\ActorRoleAssignment`'s class-level doc block for the shared
 * background; this file covers only what differs.
 *
 * ---------------------------------------------------------------------------
 * Three omissions from `scope_assignments`' shape, each explained
 * ---------------------------------------------------------------------------
 * - **No `entity_id` column.** Design doc decision 2: "Roles are orthogonal
 *   to scopes." A role answers *what kind of actor is this* (a global
 *   capability class); a scope answers *which records may this actor touch*
 *   (a row-level grant). Adding `entity_id` here would duplicate
 *   `scope_assignments` and recreate exactly the confusion a prior ruling
 *   caught: `ProvisionalScopeEntityRecipientRoleSource` answers *entity type
 *   → notification recipient role*, a different question from *actor →
 *   role*, and conflating the two was assessed as a privilege-escalation
 *   risk and rejected. This table stays answering only "actor → role".
 * - **No `users` foreign key.** Same reasoning as `scope_assignments
 *   .actor_identifier`: `ActorContext::$identityReference` is typed
 *   `int|string` precisely so a future K1/K2-backed adapter can key on an
 *   external string id, and identity is not mastered by this module. A
 *   `foreignId` tied to the local `users` table today would have to be
 *   widened or migrated the moment that adapter lands, so `actor_identifier`
 *   is a plain string column with no database-level FK integrity check.
 * - **No grant-reason or granted-by column.** Both are recorded on the
 *   `audit_events` row a later task (Task 4) writes in the same transaction
 *   as the grant/revoke — `audit_events` is the canonical record of who did
 *   what and why. Duplicating either column here would duplicate canonical
 *   catalog data across two hand-maintained locations, which `AGENTS.md`
 *   §Documentation forbids.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('actor_role_assignments', function (Blueprint $table) {
            $table->id();

            // String, not a `users` foreign key — see this file's own doc
            // block. Holds the same value as
            // `ActorContext::$identityReference` (`int|string`) for the
            // actor the grant belongs to, cast to string for storage.
            $table->string('actor_identifier');

            // App-level-validated string, not a Postgres enum — see
            // `Roles\ActorRole::KNOWN_ROLES` for the closed list this batch
            // validates against today. Extending the list is a one-line
            // change there, never a schema migration.
            $table->string('role', 64);

            // Null while active. Soft-revoke, not delete — mirrors
            // `scope_assignments.revoked_at` and `actor_sessions.revoked_at`,
            // so grant history stays queryable instead of silently
            // disappearing.
            $table->timestamp('revoked_at')->nullable();

            $table->timestamps();

            // `Roles\ActorRoleReader::rolesForActor()`'s primary access
            // path: "this actor's active role grants".
            $table->index(['actor_identifier', 'revoked_at'], 'actor_role_assignments_actor_active_idx');

            // Reverse lookup: "who holds this role" — a reasonable
            // review/audit query, and cheap to add now.
            $table->index(['role'], 'actor_role_assignments_role_idx');

            // Deliberately NO unique constraint on (actor_identifier, role):
            // a role can be granted, revoked, and re-granted, which would
            // need either a partial/conditional unique index
            // (revoked_at IS NULL) or deleting the old row on re-grant.
            // Resolution does not need uniqueness to be correct —
            // `ActorRoleReader` de-duplicates via pluck()->unique() over
            // `whereNull('revoked_at')` rows — so it is left out, for the
            // same reason `scope_assignments` omits one.
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('actor_role_assignments');
    }
};
