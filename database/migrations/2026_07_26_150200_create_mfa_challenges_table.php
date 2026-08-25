<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `mfa_challenges` — `platform-identity-and-access` design.md's data list.
 * One row per verification ATTEMPT (both TOTP challenges and recovery-code
 * redemptions — see `method` below), succeeded or failed. This is the
 * fine-grained, domain-local log this module read for its own logic
 * (nothing here reads `audit_events`, which is a separate, coarser-grained
 * cross-cutting log written via `App\Platform\Audit\Audit::record()` for
 * the same events — dual-write pattern established throughout the codebase).
 *
 * ---------------------------------------------------------------------------
 * Column shape
 * ---------------------------------------------------------------------------
 * - `method` distinguishes a TOTP challenge from a recovery-code redemption
 *   — one table serves both, rather than inventing a second undocumented
 *   table beyond `mfa_recovery_codes` (which IS separately justified — see
 *   that migration's own doc block).
 * - `outcome` is succeeded/failed — deliberately distinct from
 *   `App\Platform\Audit\AuditOutcome`'s three-way allowed/denied/failed,
 *   capturing the specific domain semantics of verification attempts.
 * - NEITHER the submitted code NOR the TOTP secret NOR a recovery code
 *   ever has a column here — requirements.md's Negative criteria: "No
 *   credential, TOTP secret, or recovery code in logs, error trackers, or
 *   audit payloads." This table only ever records THAT an attempt
 *   happened and what its outcome was.
 * - No `updated_at` — this is an append-only attempt log, same reasoning
 *   as `audit_events`/`gate_activations` in this codebase: a verification
 *   attempt is a historical fact that is never revised after the fact.
 *
 * Migration timestamp slot: `2026_07_26_150000`-`2026_07_26_159999`, same
 * batch as `mfa_enrolments`/`mfa_recovery_codes`. Runs after
 * `mfa_enrolments` so the foreign key below can reference it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mfa_challenges', function (Blueprint $table) {
            $table->id();

            $table->foreignId('mfa_enrolment_id')
                ->constrained('mfa_enrolments')
                ->cascadeOnDelete();

            $table->string('method');

            $table->string('outcome');

            $table->string('ip_address', 45)->nullable();

            $table->timestamp('occurred_at');

            $table->index(['mfa_enrolment_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mfa_challenges');
    }
};
