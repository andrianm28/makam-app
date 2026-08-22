<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Drops the 3 tables the removed MFA feature owned — see
 * `docs/adr/0024-use-session-auth-and-mfa.md`'s superseding note and
 * `docs/adr/0035-beta-launch-accepted-risks.md` item 10 for why the
 * feature was removed rather than merely left unenforced.
 *
 * Does NOT edit `2026_07_26_150000_create_mfa_enrolments_table.php` /
 * `..._150100_create_mfa_recovery_codes_table.php` /
 * `..._150200_create_mfa_challenges_table.php` — `AGENTS.md`'s
 * expand/contract migration discipline. Drop order is FK-safe: both
 * `mfa_recovery_codes` and `mfa_challenges` reference `mfa_enrolments`
 * (`cascadeOnDelete()`), so they drop first.
 *
 * `down()` deliberately does NOT recreate the tables. This codebase's own
 * convention (`AGENTS.md` §Database: "do not rely on destructive
 * production down() migrations for rollback") means rollback recovery
 * happens by restoring from a real backup, not by re-running a migration
 * that could not restore the enrolment/recovery-code data that existed
 * before this migration ran even if it did recreate empty tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('mfa_recovery_codes');
        Schema::dropIfExists('mfa_challenges');
        Schema::dropIfExists('mfa_enrolments');
    }

    public function down(): void
    {
        // Intentionally no-op — see this file's class-level doc comment.
    }
};
