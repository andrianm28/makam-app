<?php

declare(strict_types=1);

namespace Tests\Feature\IdentityAccess;

use App\Models\User;
use Filament\Panel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
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
 */
final class UserCanAccessPanelTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_access_the_admin_panel(): void
    {
        $user = User::factory()->create();

        $panel = Mockery::mock(Panel::class);
        $panel->shouldReceive('getId')->andReturn('admin');

        $this->assertTrue($user->canAccessPanel($panel));
    }

    public function test_unknown_panel_id_resolves_closed(): void
    {
        // Neither `/vendor` nor an operator panel exists in this codebase
        // yet — this asserts the default-closed behaviour rather than an
        // implicit allow for a panel id nobody declared a policy for.
        $user = User::factory()->create();

        $panel = Mockery::mock(Panel::class);
        $panel->shouldReceive('getId')->andReturn('vendor');

        $this->assertFalse($user->canAccessPanel($panel));
    }
}
