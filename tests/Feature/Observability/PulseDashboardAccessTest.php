<?php

declare(strict_types=1);

namespace Tests\Feature\Observability;

use App\Models\User;
use App\Platform\IdentityAccess\Roles\ActorRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\Support\GrantsActorRoles;
use Tests\TestCase;

/**
 * Release-gates.md §H's Pulse box ("configured and access-controlled") —
 * real functional verification now that PR #162 fixed the `composer.lock`
 * gap and `laravel/pulse` is genuinely installed (previously this whole
 * surface was unreachable: `Laravel\Pulse\...` classes did not exist,
 * `config/pulse.php` and `App\Platform\Observability\Providers\
 * ObservabilityServiceProvider` referenced a package that was not really
 * there).
 *
 * Two things are asserted, deliberately both:
 *
 * 1. The `viewPulse` gate itself — `ObservabilityServiceProvider::boot()`
 *    reuses `AdminPanelAccessPolicy`/`IdentityAccessAdapter::
 *    resolveActorContext()` verbatim (same rule as `/admin`): a guest and a
 *    roleless authenticated user are denied; the four `AdminPanelAccessPolicy::
 *    PANEL_ROLES` are admitted.
 *
 * 2. The real `/pulse` HTTP route (registered unconditionally by
 *    `Laravel\Pulse\PulseServiceProvider::registerRoutes()` regardless of
 *    `pulse.enabled` — verified by reading that class in the pinned CI
 *    image's `vendor/laravel/pulse/src/PulseServiceProvider.php`) actually
 *    consults our gate and not Pulse's own package-default `viewPulse`
 *    definition (`fn ($user = null) => $app->environment('local')`,
 *    registered in `PulseServiceProvider::registerAuthorization()` via
 *    `callAfterResolving(Gate::class, ...)`). Provider boot order
 *    (`Illuminate\Foundation\Application::registerConfiguredProviders()`
 *    splices package-discovered providers — Pulse included — between
 *    Illuminate's own providers and this app's `bootstrap/providers.php`
 *    list, and `ObservabilityServiceProvider` is the last entry in that
 *    list) means Pulse's default registers first and
 *    `ObservabilityServiceProvider`'s direct `Gate::define()` call
 *    overwrites it — but that is exactly the kind of ordering claim that
 *    deserves a real assertion, not just a comment, hence test 2 below runs
 *    against `APP_ENV=testing` (not `local`), where Pulse's own default
 *    would deny every actor including the admin — if that test passes, our
 *    gate — not Pulse's package default — is the one actually being
 *    consulted.
 */
final class PulseDashboardAccessTest extends TestCase
{
    use GrantsActorRoles;
    use RefreshDatabase;

    public function test_the_viewpulse_gate_denies_a_guest(): void
    {
        $this->assertFalse(Gate::forUser(null)->allows('viewPulse'));
    }

    public function test_the_viewpulse_gate_denies_an_authenticated_user_without_a_panel_role(): void
    {
        $user = User::factory()->create();

        $this->assertFalse(Gate::forUser($user)->allows('viewPulse'));
    }

    public function test_the_viewpulse_gate_allows_an_admin(): void
    {
        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::ADMIN);

        $this->assertTrue(Gate::forUser($user)->allows('viewPulse'));
    }

    public function test_a_guest_cannot_open_the_pulse_dashboard(): void
    {
        $this->get('/'.config('pulse.path'))
            ->assertForbidden();
    }

    public function test_an_authenticated_user_without_a_panel_role_cannot_open_the_pulse_dashboard(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/'.config('pulse.path'))
            ->assertForbidden();
    }

    public function test_an_admin_can_open_the_pulse_dashboard(): void
    {
        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::ADMIN);

        $this->actingAs($user)
            ->get('/'.config('pulse.path'))
            ->assertOk();
    }
}
