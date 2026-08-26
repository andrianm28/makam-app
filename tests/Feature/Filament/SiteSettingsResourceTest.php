<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Admin\Resources\SiteSettings\Pages\EditSiteSettings;
use App\Filament\Admin\Resources\SiteSettings\SiteSettingsResource;
use App\Models\User;
use App\Platform\IdentityAccess\Roles\ActorRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\GrantsActorRoles;
use Tests\TestCase;

final class SiteSettingsResourceTest extends TestCase
{
    use GrantsActorRoles;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_back_office_roles_can_access(): void
    {
        foreach ([ActorRole::ADMIN, ActorRole::RESTRICTED_ADMIN, ActorRole::OPERATOR, ActorRole::FINANCE] as $role) {
            $user = User::factory()->create();
            $this->grantRoleTo($user, $role);
            $this->actingAs($user);
            $this->assertTrue(SiteSettingsResource::canAccess(), "role {$role}");
        }
    }

    public function test_vendor_role_cannot_access(): void
    {
        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::VENDOR);
        $this->actingAs($user);
        $this->assertFalse(SiteSettingsResource::canAccess());
    }

    /**
     * UI-audit fix (26 Aug 2026): with no `getModelLabel()`/
     * `getPluralModelLabel()`/page `$title` override, Filament derived both
     * breadcrumb crumbs from the English class names ("Site Setting" for
     * the resource, "Edit Site Settings" for the page) — every other admin
     * resource's breadcrumb is Indonesian. Proves the rendered page no
     * longer shows either English form.
     */
    public function test_the_page_breadcrumb_is_indonesian(): void
    {
        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::ADMIN);

        $this->actingAs($user)
            ->get('/admin/pengaturan-situs')
            ->assertOk()
            ->assertSee('Pengaturan Situs')
            // Catches both "Site Setting" and "Site Settings" — the latter
            // contains the former as a substring.
            ->assertDontSee('Site Setting');
    }

    /**
     * P2 whole-branch review fix wave (D2): `save()` used to write whatever
     * `$this->data` held without running the form rules, so an invalid email
     * or an over-long value was persisted silently. The fix validates the
     * form first; this regression proves an invalid value surfaces as a field
     * error and nothing is written.
     */
    public function test_save_refuses_invalid_values_with_field_errors_and_no_persist(): void
    {
        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::ADMIN);

        Livewire::actingAs($user)
            ->test(EditSiteSettings::class)
            ->set('data.support_email', 'bukan-email')
            ->call('save')
            ->assertHasErrors('data.support_email');

        $this->assertDatabaseMissing('site_settings', ['key' => 'support_email']);
    }
}
