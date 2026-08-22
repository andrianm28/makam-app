<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `reauthentication_events` — `platform-identity-and-access` design.md's
 * Data section names this table explicitly, separate from `mfa_challenges`:
 * "Sensitive actions declare a required freshness. Middleware compares
 * `ActorContext.lastAuthenticatedAt` and challenges when stale." This table
 * is that CHALLENGE event's own log — one row per time
 * `App\Http\Middleware\RequireRecentAuthentication` decides an actor's
 * session is stale for a sensitive action (`outcome = challenged`), and one
 * row per time a future controller reports the actor successfully re-proved
 * their identity afterward (`outcome = satisfied`,
 * `Reauthentication\ReauthenticationService::satisfy()`).
 *
 * Deliberately NOT the same table as `mfa_challenges`
 * (`2026_07_26_150200_create_mfa_challenges_table.php`): that table is
 * Batch 3.6's own fine-grained TOTP-code / recovery-code verification
 * ATTEMPT log (did a submitted code match). This table is a different
 * concept one layer up — a re-authentication CHALLENGE the freshness-window
 * middleware raised, which MAY later be satisfied by a password re-entry OR
 * a code-based challenge, but is not itself a verification attempt.
 * Batch brief: "do not reuse or extend that table, this is a separate concept."
 *
 * ---------------------------------------------------------------------------
 * Column shape — mirrors `mfa_challenges`' own append-only-attempt-log
 * pattern (who, when, outcome, IP, no `updated_at`)
 * ---------------------------------------------------------------------------
 * - `actor_ref` is a plain nullable string, not a foreign key — same
 *   reasoning as `audit_events.actor_ref`
 *   (`2026_07_26_110000_create_audit_events_table.php`):
 *   `App\Platform\IdentityAccess\ActorContext::$identityReference` is typed
 *   `int|string|null` (a future K1/K2-backed adapter may reference identity
 *   by an external string id, not the local `users.id`), so this table must
 *   not assume the local `users` table is the only identity source. Nullable
 *   because a stale/guest request has no identity reference at all, and a
 *   null `lastAuthenticatedAt` (never treated as "never expires" — see the
 *   middleware's own doc block) can still trigger a challenge row.
 * - `reason` is a free string naming which sensitive-action class required
 *   the re-authentication (e.g. 'bank_account_change', 'certificate_revoke')
 *   — NOT restated here as an enum, mirroring `platform-outbox`'s own AC3
 *   "don't restate a catalogue you don't own" discipline: the six-ish
 *   sensitive-action classes (`docs/security/authentication-and-mfa.md`
 *   §5) belong to whichever future domain module actually builds each
 *   action, not to this platform-identity-access table.
 * - `outcome` is 'challenged' or 'satisfied' — deliberately distinct
 *   vocabulary from `App\Platform\Audit\AuditOutcome` (allowed/denied/failed),
 *   capturing this table's own narrower, domain-specific pair for
 *   re-authentication challenge events.
 * - No credential, TOTP secret, recovery code, or password value ever has a
 *   column here — same Negative-criteria reasoning as `mfa_challenges`. This
 *   table only ever records THAT a challenge/satisfaction happened and why.
 * - No `updated_at` — append-only attempt/event log, same reasoning as
 *   `audit_events`/`mfa_challenges`.
 *
 * Migration timestamp slot: `2026_07_26_160000` through `2026_07_26_169999`
 * (the next free range after Batch 3.6's `mfa_challenges` migration, which
 * used up to `2026_07_26_150200` inside its own `150000`-`159999` slot).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reauthentication_events', function (Blueprint $table) {
            $table->id();

            $table->string('actor_ref')->nullable();

            $table->string('actor_role');

            $table->string('reason');

            $table->string('outcome');

            $table->string('ip_address', 45)->nullable();

            $table->timestamp('occurred_at');

            $table->index(['actor_ref', 'occurred_at']);
            $table->index('reason');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reauthentication_events');
    }
};
