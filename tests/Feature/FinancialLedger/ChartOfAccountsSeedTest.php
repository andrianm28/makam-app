<?php

declare(strict_types=1);

namespace Tests\Feature\FinancialLedger;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class ChartOfAccountsSeedTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_minimal_chart_of_accounts_is_seeded_exactly(): void
    {
        $expectedAccounts = [
            ['code' => '1000', 'name' => 'Aset — Piutang Pelanggan', 'normal_balance' => 'DR'],
            ['code' => '2000', 'name' => 'Liabilitas — Pendapatan Diterima', 'normal_balance' => 'CR'],
            ['code' => '2100', 'name' => 'Liabilitas — Utang Vendor', 'normal_balance' => 'CR'],
            ['code' => '4000', 'name' => 'Pendapatan — Layanan', 'normal_balance' => 'CR'],
            ['code' => '5000', 'name' => 'HPP / Komisi Vendor', 'normal_balance' => 'DR'],
            ['code' => '6000', 'name' => 'Beban — Biaya Channel', 'normal_balance' => 'DR'],
            ['code' => '6100', 'name' => 'Beban — Refund', 'normal_balance' => 'DR'],
            ['code' => '7000', 'name' => 'Rekening Kas/Bank', 'normal_balance' => 'DR'],
        ];

        $actualAccounts = DB::table('coa_accounts')->orderBy('code')->get()->map(
            static fn (object $account): array => [
                'code' => $account->code,
                'name' => $account->name,
                'normal_balance' => $account->normal_balance,
            ],
        )->all();

        $this->assertCount(8, $actualAccounts);
        foreach ($expectedAccounts as $expectedAccount) {
            $this->assertContains($expectedAccount, $actualAccounts);
        }
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
