<?php

declare(strict_types=1);

namespace Tests\Feature\SiteSettings;

use App\Platform\SiteSettings\Models\SiteSetting;
use App\Platform\SiteSettings\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SettingsServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_when_nothing_configured(): void
    {
        $this->assertSame('09.00-17.00', app(SettingsService::class)->setting('service_hours', '09.00-17.00'));
    }

    public function test_env_is_consulted_before_db(): void
    {
        putenv('SERVICE_HOURS=07.00-18.00');
        try {
            SiteSetting::query()->create(['key' => 'service_hours', 'value' => '08.00-20.00']);
            $this->assertSame('07.00-18.00', app(SettingsService::class)->setting('service_hours', 'fallback'));
        } finally {
            putenv('SERVICE_HOURS');
        }
    }

    public function test_db_value_used_when_no_env(): void
    {
        putenv('SERVICE_HOURS');
        SiteSetting::query()->create(['key' => 'service_hours', 'value' => '08.00-20.00']);
        $this->assertSame('08.00-20.00', app(SettingsService::class)->setting('service_hours', 'fallback'));
    }

    public function test_config_overrides_env(): void
    {
        putenv('SERVICE_HOURS=07.00-18.00');
        config(['site.service_hours' => '06.00-19.00']);
        try {
            $this->assertSame('06.00-19.00', app(SettingsService::class)->setting('service_hours', 'fallback'));
        } finally {
            putenv('SERVICE_HOURS');
            config(['site.service_hours' => null]);
        }
    }
}
