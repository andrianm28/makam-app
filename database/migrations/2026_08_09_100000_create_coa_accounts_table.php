<?php

declare(strict_types=1);

use App\Platform\FinancialLedger\ChartOfAccounts;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Data-backed chart of accounts for the financial ledger foundation.
 *
 * The seven rows are the minimal approved starting set. The table remains
 * extensible so a finance owner can add accounts without a code deployment
 * or destructive rewrite of existing account codes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coa_accounts', function (Blueprint $table): void {
            $table->string('code', 16)->primary();
            $table->string('name');
            $table->string('normal_balance', 2);
        });

        DB::table('coa_accounts')->insert(ChartOfAccounts::initialAccounts());

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE coa_accounts ADD CONSTRAINT coa_accounts_normal_balance_check '.
                "CHECK (normal_balance IN ('DR', 'CR'))"
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('coa_accounts');
    }
};
