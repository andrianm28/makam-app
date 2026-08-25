<?php

declare(strict_types=1);

namespace Tests\Feature\SiteSettings;

use App\Platform\SiteSettings\Models\SiteSetting;
use App\Platform\SiteSettings\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
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

    /**
     * `App\Support\CompanyInfo`/`ContactInfo` now call this on every public
     * page, not just one narrow `service_hours` site — a database error
     * reading `site_settings` (a poisoned Postgres transaction from an
     * earlier failed query in the same request is the real-world case this
     * reproduces, see `SettingsService`'s own doc block) must fall through
     * to `$default`, never propagate and take the whole page down with it.
     */
    public function test_a_database_error_reading_site_settings_falls_through_to_the_default(): void
    {
        Schema::dropIfExists('site_settings');

        $this->assertSame('fallback', app(SettingsService::class)->setting('service_hours', 'fallback'));
    }

    public function test_a_database_error_does_not_prevent_a_second_key_lookup_in_the_same_request(): void
    {
        Schema::dropIfExists('site_settings');

        $service = app(SettingsService::class);
        $service->setting('service_hours', 'fallback');

        $this->assertSame('also-fallback', $service->setting('support_phone', 'also-fallback'));
    }
}
