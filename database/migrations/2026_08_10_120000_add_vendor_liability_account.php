<?php

declare(strict_types=1);

use App\Platform\FinancialLedger\ChartOfAccounts;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Add the approved vendor-liability account without rewriting existing COA
 * rows or journal history. The no-op down method preserves this durable
 * account during rollback of an application artifact.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('coa_accounts')->insertOrIgnore(ChartOfAccounts::VENDOR_LIABILITY_ACCOUNT);
    }

    public function down(): void
    {
        // Account rows are durable financial configuration; do not delete one
        // merely because an application migration is rolled back.
    }
};
