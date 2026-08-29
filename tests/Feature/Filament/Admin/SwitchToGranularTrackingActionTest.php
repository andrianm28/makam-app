<?php

declare(strict_types=1);

namespace Tests\Feature\Filament\Admin;

use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Domain\CemeteryDirectory\PlotTrackingMode;
use App\Filament\Admin\Resources\CemeteryResource\Pages\EditCemetery;
use App\Models\User;
use App\Platform\Audit\Models\AuditEvent;
use App\Platform\IdentityAccess\Roles\ActorRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\GrantsActorRoles;
use Tests\TestCase;

/**
 * The 'switch_to_granular_tracking' header action on `EditCemetery` — the
 * only Filament call-site for `SetCemeteryPlotTrackingMode`, which had no
 * UI wiring anywhere in the panel before this. Every actor here is granted
 * `ActorRole::ADMIN` first, same reasoning as `CemeteryResourceCrudTest`:
 * `EditCemetery` only mounts behind `CemeteryResource::canAccess()`.
 */
final class SwitchToGranularTrackingActionTest extends TestCase
{
    use GrantsActorRoles;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::ADMIN);
        $this->actingAs($user);

        return $user;
    }

    public function test_the_action_is_visible_for_an_aggregate_tier_cemetery(): void
    {
        $this->admin();

        $cemetery = Cemetery::factory()->create(['plot_tracking_mode' => PlotTrackingMode::AGGREGATE]);

        Livewire::test(EditCemetery::class, ['record' => $cemetery->getRouteKey()])
            ->assertActionVisible('switch_to_granular_tracking');
    }

    public function test_the_action_is_hidden_for_an_already_granular_cemetery(): void
    {
        $this->admin();

        $cemetery = Cemetery::factory()->create(['plot_tracking_mode' => PlotTrackingMode::GRANULAR]);

        Livewire::test(EditCemetery::class, ['record' => $cemetery->getRouteKey()])
            ->assertActionHidden('switch_to_granular_tracking');
    }

    public function test_invoking_it_switches_the_cemetery_to_granular_with_an_audit_trail(): void
    {
        $user = $this->admin();

        $cemetery = Cemetery::factory()->create(['plot_tracking_mode' => PlotTrackingMode::AGGREGATE]);

        Livewire::test(EditCemetery::class, ['record' => $cemetery->getRouteKey()])
            ->callAction('switch_to_granular_tracking')
            ->assertNotified();

        $this->assertSame(PlotTrackingMode::GRANULAR, $cemetery->fresh()->plot_tracking_mode);

        $event = AuditEvent::query()
            ->where('action', 'CEMETERY_PLOT_TRACKING_MODE_CHANGED')
            ->where('subject_id', (string) $cemetery->id)
            ->sole();

        $this->assertSame((string) $user->id, $event->actor_ref);
        $this->assertSame('admin', $event->actor_role);
        $this->assertSame('panel', $event->source);
        $this->assertSame('allowed', $event->outcome);
    }
}
