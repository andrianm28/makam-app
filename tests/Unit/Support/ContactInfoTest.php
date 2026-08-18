<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Platform\SiteSettings\Models\SiteSetting;
use App\Support\ContactInfo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `ContactInfo` — settings-aware since 18 Aug 2026. See its own doc block
 * for why every accessor is a method (not a public constant), and for
 * `whatsapp()`'s fallback-to-`phone()` behaviour specifically.
 */
final class ContactInfoTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_accessor_falls_back_to_its_placeholder_when_nothing_is_configured(): void
    {
        $this->assertSame('+62 812-0000-1234', ContactInfo::phone());
        $this->assertSame('+62 812-0000-1234', ContactInfo::whatsapp());
        $this->assertSame('bantuan@makam.co.id', ContactInfo::email());
        $this->assertSame('Senin–Jumat 08.00–17.00 WIB', ContactInfo::businessHours());
    }

    public function test_it_reads_a_real_phone_from_site_settings_once_configured(): void
    {
        SiteSetting::query()->create(['key' => SiteSetting::KEY_SUPPORT_PHONE, 'value' => '+62 811-2345-6789']);

        $this->assertSame('+62 811-2345-6789', ContactInfo::phone());
    }

    /**
     * The load-bearing case: `whatsapp()` must fall back to `phone()`'s
     * RESOLVED value, not the bare placeholder, once a real phone number is
     * configured but no separate WhatsApp number has been.
     */
    public function test_whatsapp_falls_back_to_the_resolved_phone_when_no_whatsapp_setting_exists(): void
    {
        SiteSetting::query()->create(['key' => SiteSetting::KEY_SUPPORT_PHONE, 'value' => '+62 811-2345-6789']);

        $this->assertSame('+62 811-2345-6789', ContactInfo::whatsapp());
    }

    public function test_whatsapp_can_be_configured_independently_of_phone(): void
    {
        SiteSetting::query()->create(['key' => SiteSetting::KEY_SUPPORT_PHONE, 'value' => '+62 811-2345-6789']);
        SiteSetting::query()->create(['key' => SiteSetting::KEY_SUPPORT_WHATSAPP, 'value' => '+62 822-9876-5432']);

        $this->assertSame('+62 811-2345-6789', ContactInfo::phone());
        $this->assertSame('+62 822-9876-5432', ContactInfo::whatsapp());
    }

    public function test_it_reads_a_real_email_and_hours_from_site_settings_once_configured(): void
    {
        SiteSetting::query()->create(['key' => SiteSetting::KEY_SUPPORT_EMAIL, 'value' => 'cs@makam.co.id']);
        SiteSetting::query()->create(['key' => SiteSetting::KEY_SERVICE_HOURS, 'value' => 'Senin–Sabtu 08.00–20.00 WIB']);

        $this->assertSame('cs@makam.co.id', ContactInfo::email());
        $this->assertSame('Senin–Sabtu 08.00–20.00 WIB', ContactInfo::businessHours());
    }

    public function test_summary_line_combines_the_resolved_phone_and_hours(): void
    {
        SiteSetting::query()->create(['key' => SiteSetting::KEY_SUPPORT_PHONE, 'value' => '+62 811-2345-6789']);
        SiteSetting::query()->create(['key' => SiteSetting::KEY_SERVICE_HOURS, 'value' => 'Senin–Sabtu 08.00–20.00 WIB']);

        $this->assertSame('+62 811-2345-6789 · Senin–Sabtu 08.00–20.00 WIB', ContactInfo::summaryLine());
    }
}
