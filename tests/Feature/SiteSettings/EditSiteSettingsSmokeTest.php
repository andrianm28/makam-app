<?php

declare(strict_types=1);

namespace Tests\Feature\SiteSettings;

use App\Filament\Admin\Resources\SiteSettings\Pages\EditSiteSettings;
use App\Filament\Admin\Resources\SiteSettings\SiteSettingsResource;
use App\Http\Middleware\RequireRecentAuthentication;
use App\Models\User;
use App\Platform\Audit\Models\AuditEvent;
use App\Platform\IdentityAccess\Models\ActorSession;
use App\Platform\IdentityAccess\Roles\ActorRole;
use App\Platform\SiteSettings\Models\SiteSetting;
use App\Platform\SiteSettings\SiteSettingsAuditActions;
use Carbon\CarbonImmutable;
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

    public function test_page_renders_and_non_bank_fields_save_and_audit_with_no_reauthentication(): void
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
        $component->assertFormFieldExists('data.bank_transfer_bank_name');
        $component->assertFormFieldExists('data.bank_transfer_account_number');
        $component->assertFormFieldExists('data.bank_transfer_account_holder');
        $component->assertFormFieldExists('data.bank_transfer_change_reason');

        // No seedActorSession() call here — an ordinary copy-text save must
        // work exactly as before SEC-02, with no re-authentication gate at
        // all, because none of the changed keys is bank_transfer_*.
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
        $this->assertNull(SiteSetting::valueFor(SiteSetting::KEY_BANK_TRANSFER_BANK_NAME));

        $event = AuditEvent::query()->where('action', SiteSettingsAuditActions::UPDATED)->first();
        $this->assertNotNull($event);
        $this->assertSame('site_settings', $event->subject_type);
        $this->assertStringContainsString('service_hours', (string) $event->subject_id);
        $this->assertNull($event->reason);

        $component->set('data.service_hours', 'Sama saja')->call('save')->assertNotified();
        $this->assertSame('Sama saja', SiteSetting::valueFor(SiteSetting::KEY_SERVICE_HOURS));
    }

    public function test_a_bank_transfer_change_with_a_fresh_session_and_a_reason_saves_and_audits_as_sensitive(): void
    {
        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::ADMIN);
        $this->actingAs($user);
        $this->seedActorSession($user, CarbonImmutable::now());

        Livewire::test(EditSiteSettings::class)
            ->set('data.bank_transfer_bank_name', 'Bank Makam Sejahtera')
            ->set('data.bank_transfer_account_number', '1234567890')
            ->set('data.bank_transfer_account_holder', 'PT Makam Digital Nusantara')
            ->set('data.bank_transfer_change_reason', 'Rekening lama ditutup, ini rekening operasional baru.')
            ->call('save')
            ->assertNotified();

        $this->assertSame('Bank Makam Sejahtera', SiteSetting::valueFor(SiteSetting::KEY_BANK_TRANSFER_BANK_NAME));
        $this->assertSame('1234567890', SiteSetting::valueFor(SiteSetting::KEY_BANK_TRANSFER_ACCOUNT_NUMBER));
        $this->assertSame('PT Makam Digital Nusantara', SiteSetting::valueFor(SiteSetting::KEY_BANK_TRANSFER_ACCOUNT_HOLDER));

        $event = AuditEvent::query()->where('action', SiteSettingsAuditActions::BANK_TRANSFER_UPDATED)->firstOrFail();
        $this->assertStringContainsString('bank_transfer_bank_name', (string) $event->subject_id);
        $this->assertSame('Rekening lama ditutup, ini rekening operasional baru.', $event->reason);
        $this->assertSame(0, AuditEvent::query()->where('action', SiteSettingsAuditActions::UPDATED)->count());
    }

    public function test_a_stale_session_is_redirected_and_nothing_is_written(): void
    {
        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::ADMIN);
        $this->actingAs($user);
        $this->seedActorSession($user, CarbonImmutable::now()->subHour());

        Livewire::test(EditSiteSettings::class)
            ->set('data.bank_transfer_bank_name', 'Bank Makam Sejahtera')
            ->set('data.bank_transfer_account_number', '1234567890')
            ->set('data.bank_transfer_account_holder', 'PT Makam Digital Nusantara')
            ->set('data.bank_transfer_change_reason', 'Percobaan tanpa sesi segar.')
            ->call('save')
            ->assertNotified('Perlu verifikasi ulang')
            ->assertRedirect(route('filament.admin.pages.verifikasi-ulang-kata-sandi'));

        $this->assertNull(SiteSetting::valueFor(SiteSetting::KEY_BANK_TRANSFER_BANK_NAME));
        $this->assertSame(0, AuditEvent::query()->where('action', SiteSettingsAuditActions::BANK_TRANSFER_UPDATED)->count());
        $this->assertSame('bank_account_change', session()->get(RequireRecentAuthentication::REASON_SESSION_KEY));
    }

    public function test_an_operator_cannot_change_the_bank_transfer_account(): void
    {
        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::OPERATOR);
        $this->actingAs($user);
        $this->seedActorSession($user, CarbonImmutable::now());

        Livewire::test(EditSiteSettings::class)
            ->set('data.bank_transfer_bank_name', 'Bank Makam Sejahtera')
            ->set('data.bank_transfer_account_number', '1234567890')
            ->set('data.bank_transfer_account_holder', 'PT Makam Digital Nusantara')
            ->set('data.bank_transfer_change_reason', 'Percobaan oleh operator.')
            ->call('save')
            ->assertNotified('Hanya admin, restricted admin, atau finance yang dapat mengubah rekening transfer manual.');

        $this->assertNull(SiteSetting::valueFor(SiteSetting::KEY_BANK_TRANSFER_BANK_NAME));
        $this->assertSame(0, AuditEvent::query()->where('action', SiteSettingsAuditActions::BANK_TRANSFER_UPDATED)->count());
    }

    public function test_a_bank_transfer_change_with_no_reason_is_rejected_with_nothing_written(): void
    {
        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::ADMIN);
        $this->actingAs($user);
        $this->seedActorSession($user, CarbonImmutable::now());

        Livewire::test(EditSiteSettings::class)
            ->set('data.bank_transfer_bank_name', 'Bank Makam Sejahtera')
            ->set('data.bank_transfer_account_number', '1234567890')
            ->set('data.bank_transfer_account_holder', 'PT Makam Digital Nusantara')
            ->call('save')
            ->assertHasFormErrors(['data.bank_transfer_change_reason']);

        $this->assertNull(SiteSetting::valueFor(SiteSetting::KEY_BANK_TRANSFER_BANK_NAME));
    }

    public function test_a_partial_bank_transfer_submission_is_rejected(): void
    {
        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::ADMIN);
        $this->actingAs($user);
        $this->seedActorSession($user, CarbonImmutable::now());

        Livewire::test(EditSiteSettings::class)
            ->set('data.bank_transfer_bank_name', 'Bank Makam Sejahtera')
            ->set('data.bank_transfer_change_reason', 'Percobaan parsial.')
            ->call('save')
            ->assertHasFormErrors(['data.bank_transfer_bank_name']);

        $this->assertNull(SiteSetting::valueFor(SiteSetting::KEY_BANK_TRANSFER_BANK_NAME));
    }

    public function test_resource_url_and_authorization_smoke(): void
    {
        $this->assertSame('/admin/pengaturan-situs', parse_url(SiteSettingsResource::getUrl('edit'), PHP_URL_PATH));
    }

    /**
     * The same fixture `FeatureGateAdminTest` and
     * `RequireRecentAuthenticationMiddlewareTest` use: a non-revoked
     * `actor_sessions` row with the given `last_authenticated_at`, which is
     * what `LocalUsersTableIdentityAccessAdapter::resolveLastAuthenticatedAt()`
     * reads.
     */
    private function seedActorSession(User $user, CarbonImmutable $lastAuthenticatedAt): ActorSession
    {
        return ActorSession::query()->create([
            'user_id' => $user->id,
            'session_id' => 'test-session-'.$user->id,
            'guard' => 'web',
            'last_authenticated_at' => $lastAuthenticatedAt,
        ]);
    }
}
