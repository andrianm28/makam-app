<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `scope_assignments` — `platform-identity-and-access` design.md's data
 * list ("cemetery / vendor / entity grants"). The row
 * `App\Platform\IdentityAccess\Scopes\ScopeAssignmentGlobalScope` reads to
 * enforce requirements.md AC5: "enforce record scope at query level for
 * cemetery, vendor, order, case, grave, and business entity."
 *
 * Batch 3.2 Agent C (`docs/planning/agent-execution-plan.md` §5). Migration
 * timestamp slot for this batch: `2026_07_26_130000` through
 * `2026_07_26_139999`. This file's prefix is inside that slot; sibling
 * concurrent agents in the same batch own `…_110000`–`…_119999` (feature
 * gates) and `…_120000`–`…_129999` (audit), not read by this migration.
 *
 * ---------------------------------------------------------------------------
 * Column shape — see `App\Platform\IdentityAccess\Scopes\Models
 * \ScopeAssignment`'s class-level doc block for the full reasoning; summary
 * here:
 * ---------------------------------------------------------------------------
 * - `entity_type` is a plain string, NOT a Postgres enum type — the batch
 *   brief's explicit instruction, so that adding a new entity type later is
 *   an application-layer change (`ScopeEntityType::KNOWN_TYPES`), not a
 *   schema migration. Validated at the application layer
 *   (`ScopeAssignment::booted()`'s `saving` listener), not by the database.
 * - `actor_identifier` and `entity_id` are strings rather than
 *   `foreignId`/`unsignedBigInteger` — identity is not mastered by this
 *   table (design.md: "Identity itself is NOT mastered here"), and no real
 *   domain model exists yet whose primary key type this batch could commit
 *   to. Widening this later, if a future model needs something this string
 *   column cannot express, is possible without a breaking migration; a
 *   `foreignId` chosen now against the wrong assumption would not be.
 * - `grant_level` is nullable and intentionally NOT enforced as NOT NULL —
 *   see `ScopeGrantLevel`'s doc block for why the query-scope mechanism
 *   itself does not require it.
 * - `revoked_at` mirrors `actor_sessions.revoked_at`'s already-established
 *   pattern (Batch 3.1): soft-revoke, not delete, so grant history remains
 *   queryable.
 *
 * ---------------------------------------------------------------------------
 * NOT built here — a finding, not an oversight
 * ---------------------------------------------------------------------------
 * This table has no append-only/tamper-evidence treatment of its own (no
 * write-once constraint, no database-level restriction on UPDATE/DELETE
 * the way `app/Platform/Audit/**` is reportedly applying to `audit_events`
 * — a concurrent sibling batch this agent has not seen and does not
 * integrate with). A grant/revoke on this table arguably deserves the same
 * tamper-evidence treatment access_denials/audit_events get, since it is
 * itself a security-relevant mutation. Flagged in this batch's report as a
 * finding for whoever next reconciles this table with the Audit batch's
 * output, not resolved here.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('scope_assignments', function (Blueprint $table) {
            $table->id();

            // String, not a `users` foreign key — see this file's own doc
            // block. Holds the same value as
            // `ActorContext::$identityReference` (`int|string`) for the
            // actor the grant belongs to, cast to string for storage.
            $table->string('actor_identifier');

            // App-level-validated string, not a Postgres enum — see
            // ScopeEntityType::KNOWN_TYPES for the closed list this batch
            // validates against today.
            $table->string('entity_type', 64);

            // String, not a typed foreign key — no real domain model exists
            // yet to reference (app/Domain/** is empty scaffolding).
            $table->string('entity_id');

            // Nullable, app-level-validated against ScopeGrantLevel::
            // KNOWN_LEVELS when present. See ScopeGrantLevel's doc block
            // for why ScopeAssignmentGlobalScope does not read this column.
            $table->string('grant_level', 32)->nullable();

            // Null while active. Soft-revoke, not delete — mirrors
            // actor_sessions.revoked_at (Batch 3.1).
            $table->timestamp('revoked_at')->nullable();

            $table->timestamps();

            // ScopeAssignmentResolver::grantedEntityIds()'s and
            // ::scopeStringsForActor()'s primary access path: "this actor's
            // active grants (optionally, of this entity type)".
            $table->index(['actor_identifier', 'entity_type', 'revoked_at'], 'scope_assignments_actor_type_active_idx');

            // Reverse lookup: "who has an active grant for this entity" —
            // not used by ScopeAssignmentGlobalScope today, but a
            // reasonable secondary access path for a future admin/audit
            // view over grants, and cheap to add now.
            $table->index(['entity_type', 'entity_id'], 'scope_assignments_entity_idx');

            // Deliberately NO unique constraint on
            // (actor_identifier, entity_type, entity_id): a grant can be
            // revoked and later re-granted, which would need either a
            // partial/conditional unique index (revoked_at IS NULL) or
            // deleting the old row on re-grant. This batch's mechanism does
            // not need uniqueness to be correct — ScopeAssignmentResolver
            // already de-duplicates via pluck()/whereNull('revoked_at') —
            // so it is left out rather than adding a constraint this batch
            // has not verified is safe across both the SQLite test driver
            // and production Postgres.
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('scope_assignments');
    }
};
