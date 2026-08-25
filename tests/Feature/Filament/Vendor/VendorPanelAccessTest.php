<?php

declare(strict_types=1);

namespace Tests\Feature\Filament\Vendor;

use App\Domain\Marketplace\Models\Vendor;
use App\Models\User;
use App\Platform\IdentityAccess\Roles\ActorRole;
use App\Platform\IdentityAccess\Roles\Models\ActorRoleAssignment;
use App\Platform\IdentityAccess\Scopes\Models\ScopeAssignment;
use App\Platform\IdentityAccess\Scopes\ScopeEntityType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `VendorPanelAccessPolicy` reached the way a real request reaches it: over
 * HTTP, through Filament's `Authenticate` middleware and
 * `User::canAccessPanel()`.
 *
 * `VendorPanelAccessPolicyTest` already pins the predicate itself against
 * hand-built `ActorContext` values. What that cannot show is that the wiring
 * exists — that `canAccessPanel()` has a `'vendor'` arm at all, and that it
 * resolves this policy rather than falling through to `default => false`.
 * Before this lane it did fall through, so every user was refused and no
 * amount of unit-testing the policy would have revealed it. That is precisely
 * the gap this file covers.
 */
final class VendorPanelAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Asset builds run in CI, never on a dev host (this repo's CLAUDE.md),
        // so `public/build/manifest.json` is absent here and any 200-rendering
        // assertion would fail on a missing manifest rather than on anything
        // this test is about. Same reasoning and same helper as
        // `AdminPanelHttpAccessTest`.
        $this->withoutVite();
    }

    public function test_a_vendor_with_an_active_grant_reaches_the_panel(): void
    {
        $this->actingAs($this->vendorUser(withRole: true, withGrant: true));

        $this->get('/vendor')->assertSuccessful();
    }

    public function test_a_vendor_role_without_a_grant_is_refused(): void
    {
        $this->actingAs($this->vendorUser(withRole: true, withGrant: false));

        $this->get('/vendor')->assertForbidden();
    }

    public function test_a_grant_without_the_vendor_role_is_refused(): void
    {
        $this->actingAs($this->vendorUser(withRole: false, withGrant: true));

        $this->get('/vendor')->assertForbidden();
    }

    public function test_a_user_with_neither_is_refused(): void
    {
        $this->actingAs($this->vendorUser(withRole: false, withGrant: false));

        $this->get('/vendor')->assertForbidden();
    }

    public function test_a_guest_is_sent_to_the_vendor_login_page(): void
    {
        $this->get('/vendor')->assertRedirect('/vendor/login');
    }

    public function test_every_vendor_route_is_behind_the_same_check(): void
    {
        // The panel's own routes, not just its root. A surface registered
        // outside the panel's auth middleware would be reachable by URL even
        // though the dashboard was not.
        $this->actingAs($this->vendorUser(withRole: false, withGrant: false));

        foreach (['/vendor', '/vendor/produk', '/vendor/pesanan', '/vendor/kalender', '/vendor/area-layanan', '/vendor/bukti', '/vendor/transaksi', '/vendor/pencairan'] as $route) {
            $this->get($route)->assertForbidden();
        }
    }

    public function test_a_granted_vendor_can_open_every_surface(): void
    {
        // The complement of the test above, and the one that would have caught
        // this lane's original state: the panel compiling is not the same as
        // its pages rendering. Each of these renders a real table against a
        // real (empty) scoped query.
        $this->actingAs($this->vendorUser(withRole: true, withGrant: true));

        foreach (['/vendor', '/vendor/produk', '/vendor/pesanan', '/vendor/kalender', '/vendor/area-layanan', '/vendor/bukti', '/vendor/transaksi', '/vendor/pencairan'] as $route) {
            $this->get($route)->assertSuccessful();
        }
    }

    private function vendorUser(bool $withRole, bool $withGrant): User
    {
        $user = User::factory()->create();

        if ($withRole) {
            ActorRoleAssignment::create([
                'actor_identifier' => (string) $user->id,
                'role' => ActorRole::VENDOR,
            ]);
        }

        if ($withGrant) {
            $vendor = Vendor::query()->create(['name' => 'Vendor Uji', 'is_active' => true]);

            ScopeAssignment::query()->create([
                'actor_identifier' => (string) $user->id,
                'entity_type' => ScopeEntityType::VENDOR,
                'entity_id' => (string) $vendor->id,
            ]);
        }

        return $user;
    }
}
