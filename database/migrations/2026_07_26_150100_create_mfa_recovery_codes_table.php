<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `mfa_recovery_codes` — NOT one of design.md's literally-named tables
 * (`mfa_enrolments`, `mfa_challenges`, `reauthentication_events`,
 * `scope_assignments`, `access_denials`, `anonymous_draft_tokens`). This is
 * a deliberate, documented addition, not an oversight.
 *
 * ---------------------------------------------------------------------------
 * Why a dedicated table instead of a JSON column on `mfa_enrolments`
 * ---------------------------------------------------------------------------
 * Recovery codes are the batch brief's own explicit open decision. A JSON
 * array of hashes on `mfa_enrolments` was considered and rejected: each
 * recovery code needs its OWN `used_at` (one-time consumption tracked
 * per-code, not per-enrolment), and updating a single element inside a
 * JSON array is both harder to do atomically under concurrent redemption
 * attempts and impossible to index. A dedicated table gives each code its
 * own row, its own `used_at`, and a simple `WHERE used_at IS NULL` query —
 * cleaner for exactly the one-time-consumption tracking this table exists
 * for.
 *
 * ---------------------------------------------------------------------------
 * Column shape
 * ---------------------------------------------------------------------------
 * - `code_hash` — the plaintext recovery code is NEVER stored. Hashed via
 *   Laravel's `Hash::make()` (bcrypt, matching this application's default
 *   hasher — see `config/hashing.php`) at generation time, and verified with
 *   `Hash::check()` at redemption time. This matches `docs/security/
 *   authentication-and-mfa.md` §9: "Recovery codes are one-time and stored
 *   hashed where possible."
 * - `used_at` — nullable; null means unused. Set exactly once, at
 *   redemption, never cleared — enforces single-use.
 *
 * Migration timestamp slot: `2026_07_26_150000`-`2026_07_26_159999` (same
 * batch as `mfa_enrolments`). This file's prefix
 * (`2026_07_26_150100`) is inside that slot and runs after
 * `mfa_enrolments` so the foreign key below can reference it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mfa_recovery_codes', function (Blueprint $table) {
            $table->id();

            $table->foreignId('mfa_enrolment_id')
                ->constrained('mfa_enrolments')
                ->cascadeOnDelete();

            $table->string('code_hash');

            $table->timestamp('used_at')->nullable();

            $table->timestamps();

            $table->index(['mfa_enrolment_id', 'used_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mfa_recovery_codes');
    }
};
