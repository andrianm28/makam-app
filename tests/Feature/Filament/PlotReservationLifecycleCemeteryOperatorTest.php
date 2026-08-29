<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Domain\Booking\Models\BookingDraft;
use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Domain\OrderWorkflow\Models\Order;
use App\Domain\OrderWorkflow\OrderStatus;
use App\Domain\OrderWorkflow\ProductType;
use App\Domain\PlotInventory\Models\CemeteryBlock;
use App\Domain\PlotInventory\Models\GravePlot;
use App\Domain\PlotInventory\PlotState;
use App\Domain\PlotReservation\Models\PlotReservation;
use App\Domain\PlotReservation\PlotReservationState;
use App\Filament\Admin\Resources\BookingOrders\Actions\PlotReservationLifecycleActions;
use App\Models\User;
use App\Platform\IdentityAccess\Roles\ActorRole;
use App\Platform\IdentityAccess\Scopes\Models\ScopeAssignment;
use App\Platform\IdentityAccess\Scopes\ScopeEntityType;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\GrantsActorRoles;
use Tests\TestCase;

/**
 * The three reservation lifecycle actions carry the SAME actor gate as
 * `ReservePlotAction` — including the per-order cemetery check — because
 * `/operator`'s `ViewCemeteryOrder` renders all four together. An operator
 * who can place a hold but cannot release it leaves a plot locked until an
 * admin intervenes.
 */
final class PlotReservationLifecycleCemeteryOperatorTest extends TestCase
{
    use GrantsActorRoles;
    use RefreshDatabase;

    private Cemetery $cemeteryA;

    private Cemetery $cemeteryB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        $this->cemeteryA = Cemetery::factory()->create();
        $this->cemeteryB = Cemetery::factory()->create();
    }

    /**
     * @return array{Order, PlotReservation}
     */
    private function heldReservationIn(Cemetery $cemetery): array
    {
        $draft = BookingDraft::query()->create(['cemetery_id' => $cemetery->id]);
        $order = Order::query()->create([
            'reference' => 'MK-2026-'.Str::upper(Str::random(8)),
            'product_type' => ProductType::AT_NEED_SERVICE_ORDER->value,
            'status' => OrderStatus::DIVERIFIKASI->value,
            'booking_draft_id' => $draft->id,
        ]);
        $block = CemeteryBlock::query()->create([
            'cemetery_id' => $cemetery->id,
            'code' => 'BLOK-'.Str::upper(Str::random(4)),
            'name' => 'Blok uji',
            'capacity' => 5,
            'is_active' => true,
        ]);
        $plot = GravePlot::query()->create([
            'block_id' => $block->id,
            'slot' => 'S-'.Str::upper(Str::random(4)),
            'plot_state' => PlotState::RESERVED,
        ]);
        $reservation = PlotReservation::query()->create([
            'plot_id' => $plot->id,
            'order_id' => $order->id,
            'state' => PlotReservationState::HELD,
            'reserved_by_ref' => '1',
            'reserved_at' => CarbonImmutable::now(),
        ]);

        return [$order, $reservation];
    }

    private function actingAsCemeteryOperatorGrantedTo(Cemetery $cemetery): void
    {
        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::CEMETERY_OPERATOR);
        ScopeAssignment::query()->create([
            'actor_identifier' => (string) $user->id,
            'entity_type' => ScopeEntityType::CEMETERY,
            'entity_id' => (string) $cemetery->id,
        ]);
        $this->actingAs($user);
        $this->app->forgetScopedInstances();
    }

    public function test_an_operator_may_run_all_three_lifecycle_actions_on_their_own_cemeterys_hold(): void
    {
        [$order, $reservation] = $this->heldReservationIn($this->cemeteryA);
        $this->actingAsCemeteryOperatorGrantedTo($this->cemeteryA);

        $this->assertTrue(PlotReservationLifecycleActions::confirm($order, $reservation)->isAuthorized());
        $this->assertTrue(PlotReservationLifecycleActions::release($order, $reservation)->isAuthorized());
        $this->assertTrue(PlotReservationLifecycleActions::expire($order, $reservation)->isAuthorized());
    }

    public function test_an_operator_may_not_run_them_on_another_cemeterys_hold(): void
    {
        [$order, $reservation] = $this->heldReservationIn($this->cemeteryB);
        $this->actingAsCemeteryOperatorGrantedTo($this->cemeteryA);

        $this->assertFalse(PlotReservationLifecycleActions::confirm($order, $reservation)->isAuthorized());
        $this->assertFalse(PlotReservationLifecycleActions::release($order, $reservation)->isAuthorized());
        $this->assertFalse(PlotReservationLifecycleActions::expire($order, $reservation)->isAuthorized());
    }

    public function test_the_platform_wide_roles_are_unaffected(): void
    {
        [$order, $reservation] = $this->heldReservationIn($this->cemeteryB);

        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::OPERATOR);
        $this->actingAs($user);
        $this->app->forgetScopedInstances();

        $this->assertTrue(PlotReservationLifecycleActions::confirm($order, $reservation)->isAuthorized());
    }

    public function test_finance_is_still_refused(): void
    {
        [$order, $reservation] = $this->heldReservationIn($this->cemeteryA);

        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::FINANCE);
        $this->actingAs($user);
        $this->app->forgetScopedInstances();

        $this->assertFalse(PlotReservationLifecycleActions::confirm($order, $reservation)->isAuthorized());
    }
}
