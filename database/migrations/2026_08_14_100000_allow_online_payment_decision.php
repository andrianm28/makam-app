<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Widens `payment_intents.decision`'s Postgres CHECK constraint to admit
 * `'allowed'` on databases that already applied
 * `2026_08_09_100000_create_payment_intents_table.php` (which generated the
 * CHECK from `PaymentIntentDecision::values()` at that time — a single
 * `'denied'`).
 *
 * Fresh databases need nothing from this migration: the create migration
 * regenerates the CHECK from `::values()` at apply time, which now yields
 * `('denied', 'allowed')`. This additive ALTER exists for the already-
 * applied databases (dev), and is guarded to the pgsql driver for the same
 * reason every CHECK migration in this module is: SQLite cannot ADD
 * CONSTRAINT (phpunit.xml defaults to sqlite; CI and every real environment
 * run Postgres).
 *
 * Idempotent-safe by construction: `DROP CONSTRAINT IF EXISTS` followed by
 * an unconditional `ADD CONSTRAINT` is deterministic on pgsql regardless of
 * which CHECK version is currently attached.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE payment_intents DROP CONSTRAINT IF EXISTS payment_intents_decision_check');
        DB::statement("ALTER TABLE payment_intents ADD CONSTRAINT payment_intents_decision_check CHECK (decision IN ('denied', 'allowed'))");
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE payment_intents DROP CONSTRAINT IF EXISTS payment_intents_decision_check');
        DB::statement("ALTER TABLE payment_intents ADD CONSTRAINT payment_intents_decision_check CHECK (decision IN ('denied'))");
    }
};
