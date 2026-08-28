<?php

declare(strict_types=1);

namespace Tests\Feature\IdentityAccess;

use App\Models\User;
use App\Platform\IdentityAccess\Roles\ActorRole;
use Filament\Panel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\Support\GrantsActorRoles;
use Tests\TestCase;

/**
 * End-to-end AC4 wiring: `User::canAccessPanel()` (the `FilamentUser`
 * contract method Filament actually calls) through to
 * `AdminPanelAccessPolicy` via the container.
 *
 * VERIFICATION STATUS: depends on `Filament\Panel` being autoloadable,
 * which requires `vendor/filament/filament` — not installed on this host
 * (see the batch report's NOT TESTED section). This test is written to run
 * in CI, where the real package is installed.
 *
 * The allowed fixture carries a REAL `operator` role grant, not a
 * hand-built `ActorContext`: `canAccessPanel()` resolves the candidate
 * user's context fresh through `LocalUsersTableIdentityAccessAdapter`
 * (reading `actor_role_assignments`), so only a real grant exercises the
 * whole chain. This matches Task 10 of the L9 `admin-operations` lane,
 * which restricted the panel to the four back-office roles — `operator`
 * is the least role that makes this test's "some panel user" intent true.
 */
final class UserCanAccessPanelTest extends TestCase
{
    use GrantsActorRoles;
    use RefreshDatabase;

    public function test_operator_can_access_the_admin_panel(): void
    {
        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::OPERATOR);

        $panel = Mockery::mock(Panel::class);
        $panel->shouldReceive('getId')->andReturn('admin');

        $this->assertTrue($user->canAccessPanel($panel));
    }

    public function test_a_roleless_user_cannot_access_the_admin_panel(): void
    {
        $user = User::factory()->create();

        $panel = Mockery::mock(Panel::class);
        $panel->shouldReceive('getId')->andReturn('admin');

        $this->assertFalse($user->canAccessPanel($panel));
    }

    public function test_unknown_panel_id_resolves_closed(): void
    {
        // `admin`, `vendor`, and `operator` are all real `match` arms in
        // `User::canAccessPanel()` now — this exercises the `default =>
        // false` arm with a panel id nobody declared a policy for, so it
        // actually reaches the fallback instead of coincidentally passing
        // via a real policy's own denial.
        $user = User::factory()->create();

        $panel = Mockery::mock(Panel::class);
        $panel->shouldReceive('getId')->andReturn('nonexistent');

        $this->assertFalse($user->canAccessPanel($panel));
    }
}
