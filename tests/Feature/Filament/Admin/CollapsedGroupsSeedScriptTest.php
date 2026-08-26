<?php

declare(strict_types=1);

namespace Tests\Feature\Filament\Admin;

use App\Models\User;
use App\Platform\IdentityAccess\Roles\ActorRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GrantsActorRoles;
use Tests\TestCase;

/**
 * UI-audit fix (26 Aug 2026): `AdminPanelProvider::panel()` registers a
 * `PanelsRenderHook::HEAD_END` hook that seeds `localStorage['collapsedGroups']`
 * to `[]` before Alpine's sidebar store can default it to `null` — see that
 * hook's own inline doc block for the full `Cannot read properties of null
 * (reading 'includes')` root-cause trail.
 *
 * `HEAD_END` is rendered by `vendor/filament/filament/resources/views/
 * components/layout/base.blade.php`, which BOTH the authenticated app
 * layout and the login/"simple" layout extend, so the hook must fire on
 * BOTH — the whole point is closing the race regardless of which page a
 * visitor's browser reaches first (most commonly login, which has no
 * sidebar at all and therefore never runs Filament's own localStorage-init
 * script).
 */
final class CollapsedGroupsSeedScriptTest extends TestCase
{
    use GrantsActorRoles;
    use RefreshDatabase;

    private const string SEED_SNIPPET = "localStorage.setItem('collapsedGroups', JSON.stringify([]));";

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_the_seed_script_renders_on_the_login_page(): void
    {
        $this->get(route('filament.admin.auth.login'))
            ->assertOk()
            ->assertSee(self::SEED_SNIPPET, false);
    }

    public function test_the_seed_script_renders_on_the_authenticated_dashboard(): void
    {
        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::OPERATOR);

        $this->actingAs($user)
            ->get('/admin')
            ->assertOk()
            ->assertSee(self::SEED_SNIPPET, false);
    }
}
