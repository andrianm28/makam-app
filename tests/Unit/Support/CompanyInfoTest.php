<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Platform\SiteSettings\Models\SiteSetting;
use App\Support\CompanyInfo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `CompanyInfo` — settings-aware since 18 Aug 2026. See its own doc block
 * for why `name()`/`address()` are methods (not public constants) and what
 * they fall back to.
 */
final class CompanyInfoTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_falls_back_to_the_placeholder_when_nothing_is_configured(): void
    {
        $this->assertSame('PT Contoh Makam Digital Indonesia', CompanyInfo::name());
        $this->assertStringContainsString('Jl. Contoh', CompanyInfo::address());
    }

    public function test_it_reads_a_real_name_and_address_from_site_settings_once_configured(): void
    {
        SiteSetting::query()->create(['key' => SiteSetting::KEY_COMPANY_NAME, 'value' => 'PT Makam Digital Nusantara']);
        SiteSetting::query()->create(['key' => SiteSetting::KEY_COMPANY_ADDRESS, 'value' => 'Jl. Sudirman No. 1, Jakarta Pusat']);

        $this->assertSame('PT Makam Digital Nusantara', CompanyInfo::name());
        $this->assertSame('Jl. Sudirman No. 1, Jakarta Pusat', CompanyInfo::address());
    }

    public function test_configuring_only_the_name_leaves_the_address_on_its_own_fallback(): void
    {
        SiteSetting::query()->create(['key' => SiteSetting::KEY_COMPANY_NAME, 'value' => 'PT Makam Digital Nusantara']);

        $this->assertSame('PT Makam Digital Nusantara', CompanyInfo::name());
        $this->assertStringContainsString('Jl. Contoh', CompanyInfo::address());
    }

    /**
     * Unlike `name()`/`address()`, `nib()` has no fictional placeholder
     * fallback — see its own doc block for why a fake NIB pattern would be
     * actively misleading rather than self-labelling as a placeholder.
     */
    public function test_nib_is_null_until_an_operator_configures_one(): void
    {
        $this->assertNull(CompanyInfo::nib());
    }

    public function test_nib_reads_the_configured_value(): void
    {
        SiteSetting::query()->create(['key' => SiteSetting::KEY_COMPANY_NIB, 'value' => '1234567890123']);

        $this->assertSame('1234567890123', CompanyInfo::nib());
    }

    public function test_a_blank_nib_value_is_treated_as_not_configured(): void
    {
        SiteSetting::query()->create(['key' => SiteSetting::KEY_COMPANY_NIB, 'value' => '   ']);

        $this->assertNull(CompanyInfo::nib());
    }
}
