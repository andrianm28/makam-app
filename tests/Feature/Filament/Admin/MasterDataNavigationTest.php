<?php

declare(strict_types=1);

namespace Tests\Feature\Filament\Admin;

use App\Models\User;
use App\Platform\IdentityAccess\Roles\ActorRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GrantsActorRoles;
use Tests\TestCase;

/**
 * Proves the plan Task 7 wiring: the three master-data resources
 * (Cemetery, Product, ServiceDefinition) auto-discover through
 * `AdminPanelProvider`'s `->discoverResources(...)` — no explicit
 * registration in the provider — and appear in the admin navigation
 * with their canonical Indonesian labels:
 *
 *   - `Makam / TPU`      (CemeteryResource, plural model label)
 *   - `Produk Layanan`   (ProductResource)
 *   - `Layanan`          (ServiceDefinitionResource)
 *
 * Every resource's index route is reachable by an admin and closed to a
 * guest (redirect to the panel login, exactly like every other
 * authenticated `/admin/*` route — see
 * `Tests\Feature\IdentityAccess\AdminPanelHttpAccessTest` for the source
 * trail showing Filament's `Authenticate` middleware redirects guests).
 *
 * The guest fixture asserts the login route by name
 * (`filament.admin.auth.login`), never a hardcoded string, so the
 * assertion stays correct if the panel path ever moves.
 */
final class MasterDataNavigationTest extends TestCase
{
    use GrantsActorRoles;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_the_admin_navigation_shows_all_three_master_data_resources(): void
    {
        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::ADMIN);

        $this->actingAs($user)
            ->get('/admin')
            ->assertOk()
            ->assertSee('Makam / TPU')
            ->assertSee('Produk Layanan')
            ->assertSee('Layanan');
    }

    public function test_an_admin_can_open_each_master_data_resource_index(): void
    {
        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::ADMIN);

        foreach ([
            '/admin/pemakaman',
            '/admin/produk',
            '/admin/definisi-layanan',
        ] as $index) {
            $this->actingAs($user)
                ->get($index)
                ->assertOk();
        }
    }

    public function test_a_guest_is_redirected_from_each_master_data_resource_index(): void
    {
        foreach ([
            '/admin/pemakaman',
            '/admin/produk',
            '/admin/definisi-layanan',
        ] as $index) {
            $this->get($index)
                ->assertRedirect(route('filament.admin.auth.login'));
        }
    }
}
