<?php

declare(strict_types=1);

namespace Tests\Feature\SiteSettings;

use App\Filament\Admin\Resources\SiteSettings\Pages\EditSiteSettings;
use App\Filament\Admin\Resources\SiteSettings\SiteSettingsResource;
use App\Models\User;
use App\Platform\Audit\Models\AuditEvent;
use App\Platform\IdentityAccess\Roles\ActorRole;
use App\Platform\SiteSettings\Models\SiteSetting;
use App\Platform\SiteSettings\SiteSettingsAuditActions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\GrantsActorRoles;
use Tests\TestCase;

final class EditSiteSettingsSmokeTest extends TestCase
{
    use GrantsActorRoles;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_page_renders_and_save_upserts_and_audits(): void
    {
        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::ADMIN);
        $this->actingAs($user);

        $component = Livewire::test(EditSiteSettings::class);

        $component->assertFormFieldExists('data.service_hours');
        $component->assertFormFieldExists('data.support_email');
        $component->assertFormFieldExists('data.company_name');
        $component->assertFormFieldExists('data.company_address');
        $component->assertFormFieldExists('data.company_nib');
        $component->assertFormFieldExists('data.legal_review_note');

        $component
            ->set('data.service_hours', 'Senin-Jumat 09.00-18.00')
            ->set('data.payment_merchant_ref', 'MERCH-1')
            ->set('data.company_name', 'PT Makam Digital Nusantara')
            ->set('data.company_nib', '1234567890123')
            ->set('data.legal_review_note', 'Ditinjau 1 Sep 2026 oleh Firma Hukum Contoh')
            ->call('save')
            ->assertNotified();

        $this->assertSame('Senin-Jumat 09.00-18.00', SiteSetting::valueFor(SiteSetting::KEY_SERVICE_HOURS));
        $this->assertSame('MERCH-1', SiteSetting::valueFor(SiteSetting::KEY_PAYMENT_MERCHANT_REF));
        $this->assertSame('PT Makam Digital Nusantara', SiteSetting::valueFor(SiteSetting::KEY_COMPANY_NAME));
        $this->assertSame('1234567890123', SiteSetting::valueFor(SiteSetting::KEY_COMPANY_NIB));
        $this->assertSame('Ditinjau 1 Sep 2026 oleh Firma Hukum Contoh', SiteSetting::valueFor(SiteSetting::KEY_LEGAL_REVIEW_NOTE));

        $event = AuditEvent::query()->where('action', SiteSettingsAuditActions::UPDATED)->first();
        $this->assertNotNull($event);
        $this->assertSame('site_settings', $event->subject_type);
        $this->assertStringContainsString('service_hours', (string) $event->subject_id);

        $component->set('data.service_hours', 'Sama saja')->call('save')->assertNotified();
        $this->assertSame('Sama saja', SiteSetting::valueFor(SiteSetting::KEY_SERVICE_HOURS));
    }

    public function test_resource_url_and_authorization_smoke(): void
    {
        $this->assertSame('/admin/pengaturan-situs', parse_url(SiteSettingsResource::getUrl('edit'), PHP_URL_PATH));
    }
}
