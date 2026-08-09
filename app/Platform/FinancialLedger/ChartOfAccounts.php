<?php

declare(strict_types=1);

namespace App\Platform\FinancialLedger;

/**
 * The explicit, minimal initial chart of accounts for the shared ledger.
 *
 * These rows are seed data, not a closed application enum. Finance may add
 * accounts by inserting new codes without rewriting existing ledger history.
 */
final class ChartOfAccounts
{
    /**
     * @var list<array{code: string, name: string, normal_balance: string}>
     */
    public const array MINIMAL_INITIAL_ACCOUNTS = [
        ['code' => '1000', 'name' => 'Aset — Piutang Pelanggan', 'normal_balance' => 'DR'],
        ['code' => '2000', 'name' => 'Liabilitas — Pendapatan Diterima', 'normal_balance' => 'CR'],
        ['code' => '4000', 'name' => 'Pendapatan — Layanan', 'normal_balance' => 'CR'],
        ['code' => '5000', 'name' => 'HPP / Komisi Vendor', 'normal_balance' => 'DR'],
        ['code' => '6000', 'name' => 'Beban — Biaya Channel', 'normal_balance' => 'DR'],
        ['code' => '6100', 'name' => 'Beban — Refund', 'normal_balance' => 'DR'],
        ['code' => '7000', 'name' => 'Rekening Kas/Bank', 'normal_balance' => 'DR'],
    ];

    /**
     * @return list<array{code: string, name: string, normal_balance: string}>
     */
    public static function initialAccounts(): array
    {
        return self::MINIMAL_INITIAL_ACCOUNTS;
    }
}
