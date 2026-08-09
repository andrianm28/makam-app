<?php

declare(strict_types=1);

namespace Tests\Feature\FinancialLedger;

use App\Platform\FinancialLedger\ChartOfAccounts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class ChartOfAccountsSeedTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_minimal_chart_of_accounts_is_seeded_exactly(): void
    {
        $this->assertSame(ChartOfAccounts::initialAccounts(), DB::table('coa_accounts')->orderBy('code')->get()->map(
            static fn (object $account): array => [
                'code' => $account->code,
                'name' => $account->name,
                'normal_balance' => $account->normal_balance,
            ],
        )->all());
    }

    public function test_the_chart_of_accounts_accepts_additive_finance_owner_accounts(): void
    {
        DB::table('coa_accounts')->insert([
            'code' => '8000',
            'name' => 'Akun Tambahan Finance',
            'normal_balance' => 'CR',
        ]);

        $this->assertDatabaseHas('coa_accounts', [
            'code' => '8000',
            'name' => 'Akun Tambahan Finance',
            'normal_balance' => 'CR',
        ]);
    }
}
