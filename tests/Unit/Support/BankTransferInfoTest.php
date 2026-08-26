<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Platform\SiteSettings\Models\SiteSetting;
use App\Support\BankTransferInfo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `BankTransferInfo` — the manual bank-transfer destination shown on the
 * booking wizard's Step 8 fallback card. Unlike `ContactInfo`/`CompanyInfo`'s
 * placeholder defaults, this class has NO fictional fallback (same
 * discipline as `CompanyInfo::nib()`) — see its own doc block for why.
 */
final class BankTransferInfoTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_accessor_is_null_until_an_operator_configures_it(): void
    {
        $this->assertNull(BankTransferInfo::bankName());
        $this->assertNull(BankTransferInfo::accountNumber());
        $this->assertNull(BankTransferInfo::accountHolder());
        $this->assertFalse(BankTransferInfo::isConfigured());
    }

    public function test_it_reads_real_values_once_all_three_are_configured(): void
    {
        SiteSetting::query()->create(['key' => SiteSetting::KEY_BANK_TRANSFER_BANK_NAME, 'value' => 'Bank Makam Sejahtera']);
        SiteSetting::query()->create(['key' => SiteSetting::KEY_BANK_TRANSFER_ACCOUNT_NUMBER, 'value' => '1234567890']);
        SiteSetting::query()->create(['key' => SiteSetting::KEY_BANK_TRANSFER_ACCOUNT_HOLDER, 'value' => 'PT Makam Digital Nusantara']);

        $this->assertSame('Bank Makam Sejahtera', BankTransferInfo::bankName());
        $this->assertSame('1234567890', BankTransferInfo::accountNumber());
        $this->assertSame('PT Makam Digital Nusantara', BankTransferInfo::accountHolder());
        $this->assertTrue(BankTransferInfo::isConfigured());
    }

    /**
     * The load-bearing case: a partial configuration (only one or two of the
     * three fields set) must NOT be treated as configured — a bank name with
     * no account number is unusable to a payer.
     */
    public function test_a_partial_configuration_is_not_treated_as_configured(): void
    {
        SiteSetting::query()->create(['key' => SiteSetting::KEY_BANK_TRANSFER_BANK_NAME, 'value' => 'Bank Makam Sejahtera']);
        SiteSetting::query()->create(['key' => SiteSetting::KEY_BANK_TRANSFER_ACCOUNT_NUMBER, 'value' => '1234567890']);

        $this->assertSame('Bank Makam Sejahtera', BankTransferInfo::bankName());
        $this->assertSame('1234567890', BankTransferInfo::accountNumber());
        $this->assertNull(BankTransferInfo::accountHolder());
        $this->assertFalse(BankTransferInfo::isConfigured());
    }

    public function test_a_blank_value_is_treated_as_not_configured(): void
    {
        SiteSetting::query()->create(['key' => SiteSetting::KEY_BANK_TRANSFER_BANK_NAME, 'value' => '   ']);
        SiteSetting::query()->create(['key' => SiteSetting::KEY_BANK_TRANSFER_ACCOUNT_NUMBER, 'value' => '1234567890']);
        SiteSetting::query()->create(['key' => SiteSetting::KEY_BANK_TRANSFER_ACCOUNT_HOLDER, 'value' => 'PT Makam Digital Nusantara']);

        $this->assertNull(BankTransferInfo::bankName());
        $this->assertFalse(BankTransferInfo::isConfigured());
    }
}
