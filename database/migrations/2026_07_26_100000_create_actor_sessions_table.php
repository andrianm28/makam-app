<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `actor_sessions` — platform-identity-and-access design.md's data list.
 *
 * Scope for THIS batch (S3-T1, requirements.md AC1 + AC8 only): a session
 * record adequate for (a) `LocalUsersTableIdentityAccessAdapter` resolving
 * `ActorContext.lastAuthenticatedAt`, and (b) giving AC7's later "immediate
 * session revocation across all actor sessions" something to act on. This
 * migration deliberately does NOT implement AC7 revocation semantics
 * (broadcast/cascade revoke-all is a later task line, not this batch) — it
 * only adds the `revoked_at` column so that future work has a column to
 * write to instead of a schema change plus a data migration.
 *
 * Deliberately NOT built here (design.md names these; other batches own
 * them): `mfa_enrolments`, `mfa_challenges`, `reauthentication_events`,
 * `scope_assignments`, `access_denials`, `anonymous_draft_tokens`.
 *
 * Identity is not mastered by this table (AC12 / design.md: "Identity
 * itself is NOT mastered here; only references and platform-local
 * authorization state") — `user_id` is a reference into the existing
 * `users` table (Laravel's stock Authenticatable, itself the MVP stand-in
 * for the still-unseen K1/K2 identity contract), never a duplicate
 * identity record.
 *
 * Migration timestamp slot for this batch: 2026_07_26_100000 through
 * 2026_07_26_109999 (agent-execution-plan.md §5, Batch 3.1). This file's
 * prefix (`2026_07_26_100000`) is inside that slot.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('actor_sessions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete();

            // Correlates to the framework's own `sessions.id` (see
            // 0001_01_01_000000_create_users_table.php) so a future
            // revocation task can find and invalidate the matching session
            // row. Not a foreign key: the `sessions` table is keyed by a
            // string id with no formal FK contract in Laravel's own schema,
            // and session rows may be pruned by the session garbage
            // collector independently of actor_sessions bookkeeping.
            //
            // Nullable + best-effort: `RecordActorSessionOnLogin` falls
            // back to a generated UUID when the `Login` event fires outside
            // an HTTP request with a bound session (e.g. a console-context
            // login) — see that listener's doc block. So this column is
            // "best correlation available", not a guaranteed 1:1 join key.
            $table->string('session_id');

            // Which auth guard authenticated this session. Only the `web`
            // guard exists today (config/auth.php; the `/admin` Filament
            // panel does not declare a distinct ->authGuard(), so it also
            // authenticates through `web`). Recorded now rather than added
            // later because a future panel (`/vendor`, operator) is likely
            // to introduce its own guard, and AC7 revocation will need to
            // know which guard's session it is revoking.
            $table->string('guard')->default('web');

            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();

            // Source for ActorContext::$lastAuthenticatedAt (AC8) today,
            // and the freshness comparison S3-T2/T3's re-authentication
            // middleware will read tomorrow (design.md §Re-authentication:
            // "Middleware compares ActorContext.lastAuthenticatedAt").
            $table->timestamp('last_authenticated_at');

            // Null while active. Set by RecordActorSessionOnLogout for the
            // single session being logged out of (self-logout bookkeeping
            // only — NOT the AC7 "revoke every session for this actor"
            // capability, which is out of scope for this batch). Also the
            // column AC7's future implementation will write to for a real
            // revoke-all action, and the column
            // LocalUsersTableIdentityAccessAdapter filters out when
            // resolving the most recent authentication.
            $table->timestamp('revoked_at')->nullable();

            $table->timestamps();

            $table->unique(['user_id', 'session_id']);
            $table->index(['user_id', 'revoked_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('actor_sessions');
    }
};
