<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `mfa_enrolments` — `platform-identity-and-access` design.md's data list.
 * S3-T2 (HUMAN-GATED — see `docs/planning/agent-execution-plan.md`'s
 * "HUMAN — S3-T2 MFA, S3-T3 re-authentication" note). This table has since
 * been deleted; this migration documents its historical schema. See Task 4
 * of the MFA removal plan for context on the removal.
 *
 * ---------------------------------------------------------------------------
 * Column shape
 * ---------------------------------------------------------------------------
 * - `user_id` is a `foreignId`, NOT the loosely-typed string identifier
 *   `scope_assignments.actor_identifier` / `gate_activations.actor_reference`
 *   use. Those tables are deliberately identity-source-agnostic because
 *   they anticipate a future K1/K2-backed adapter. This table is different:
 *   it is inherently local-auth-specific machinery (a TOTP secret keyed to
 *   THIS application's own session guard), mirroring `actor_sessions
 *   .user_id`'s own `foreignId` choice (Batch 3.1) for the identical
 *   reason — MFA enrolment as built here is a property of the local
 *   `users` table's identity, not a platform-wide concept yet.
 * - `secret` is `text`, not `string`, because Laravel's `encrypted` cast
 *   (AES-256-CBC + base64 + MAC framing) produces ciphertext well over 255
 *   characters even for a 20-byte Base32 secret.
 * - `status` is a plain string validated at the application layer, not
 *   a Postgres enum — mirrors the established pattern used in other
 *   identity-access tables like `scope_assignments.entity_type`.
 * - `digits`/`period_seconds` are stored per-row (not read from a single
 *   global config value) so a historical enrolment's parameters remain
 *   self-describing even if this module's own defaults ever change.
 * - `last_verified_counter` is the REPLAY-PROTECTION column: the last
 *   TOTP time-step counter successfully verified for this enrolment.
 *   `Totp::verify()`'s own doc block explains exactly why a counter that
 *   is not strictly greater than this is always rejected, even if
 *   mathematically valid and inside the tolerance window.
 * - `confirmed_at` / `revoked_at` mirror the soft-lifecycle pattern already
 *   established by `actor_sessions.revoked_at` / `scope_assignments
 *   .revoked_at` in this same module — history is kept, never deleted.
 *
 * No uniqueness constraint enforces "one active enrolment per actor" at
 * the database level (deliberately — the invariant was enforced
 * in application code, inside a transaction with a row lock, rather than
 * at the schema level).
 *
 * Migration timestamp slot for this batch: `2026_07_26_150000` through
 * `2026_07_26_159999` (agent-execution-plan.md's next free range after
 * Batch 3.3's `…_140000`). This file's prefix is inside that slot.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mfa_enrolments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->text('secret');

            $table->string('status')->default('pending');

            $table->unsignedTinyInteger('digits')->default(6);

            $table->unsignedSmallInteger('period_seconds')->default(30);

            $table->unsignedBigInteger('last_verified_counter')->nullable();

            $table->timestamp('confirmed_at')->nullable();

            $table->timestamp('revoked_at')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mfa_enrolments');
    }
};
