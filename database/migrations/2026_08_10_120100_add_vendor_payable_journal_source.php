<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Extend the journal source list for the approved vendor-payable accrual.
 * Existing rows are untouched; only the accepted value set changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE journal_batches DROP CONSTRAINT IF EXISTS journal_batches_source_type_check');
        DB::statement(
            'ALTER TABLE journal_batches ADD CONSTRAINT journal_batches_source_type_check '.
            "CHECK (source_type IN ('payment', 'manual_verification', 'renewal', 'refund', 'chargeback', 'payout', 'reversal', 'vendor_payable'))"
        );
    }

    public function down(): void
    {
        // Removing an accepted source value could invalidate committed rows.
    }
};
