<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Domain\Booking\Models\BookingDraft;
use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Domain\CemeteryDirectory\PlotTrackingMode;
use App\Domain\OrderWorkflow\Models\Order;
use App\Domain\OrderWorkflow\OrderStatus;
use App\Domain\OrderWorkflow\ProductType;
use App\Domain\PlotInventory\Actions\CreateCemeteryBlock;
use App\Domain\PlotInventory\Models\CemeteryBlock;
use App\Domain\PlotInventory\Models\GravePlot;
use App\Domain\PlotInventory\PlotInventoryAuditActions;
use App\Domain\PlotInventory\PlotState;
use App\Domain\PlotReservation\Actions\ConfirmPlotReservation;
use App\Domain\PlotReservation\Actions\ReservePlot;
use App\Domain\PlotReservation\Models\PlotReservation;
use App\Domain\PlotReservation\PlotReservationState;
use App\Filament\Admin\Pages\PlotFloorMap as AdminPlotFloorMap;
use App\Filament\Operator\Pages\PlotFloorMap as OperatorPlotFloorMap;
use App\Filament\Shared\PlotInventory\PlotStateOverrides;
use App\Models\User;
use App\Platform\Audit\Models\AuditEvent;
use App\Platform\IdentityAccess\Models\ActorSession;
use App\Platform\IdentityAccess\Roles\ActorRole;
use App\Platform\IdentityAccess\Scopes\Models\ScopeAssignment;
use App\Platform\IdentityAccess\Scopes\ScopeEntityType;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\GrantsActorRoles;
use Tests\TestCase;

/**
 * The write half of the Phase D plot availability dashboard: the three
 * audited state overrides reached from a plot cell.
 *
 * Every assertion is against real rows — the flipped `grave_plots.plot_state`
 * and the `audit_events` row the write must produce — not against a mocked
 * action. The re-authentication fixture is the one
 * `PlotInventoryAdminTest` established (`actor_sessions
 * .last_authenticated_at`, read by
 * `LocalUsersTableIdentityAccessAdapter::resolveLastAuthenticatedAt`).
 */
final class PlotFloorMapActionsTest extends TestCase
{
    use GrantsActorRoles;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function seedActorSession(User $user, CarbonImmutable $lastAuthenticatedAt): void
    {
        ActorSession::query()->create([
            'user_id' => $user->id,
            'session_id' => 'test-session-'.$user->id,
            'guard' => 'web',
            'last_authenticated_at' => $lastAuthenticatedAt,
        ]);
    }

    private function freshAdmin(): User
    {
        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::ADMIN);
        $this->actingAs($user);
        $this->seedActorSession($user, CarbonImmutable::now());

        return $user;
    }

    /**
     * @return array{0: Cemetery, 1: CemeteryBlock}
     */
    private function granularCemetery(User $actor, int $capacity = 3): array
    {
        $cemetery = Cemetery::factory()->create(['plot_tracking_mode' => PlotTrackingMode::GRANULAR]);
        $block = app(CreateCemeteryBlock::class)($cemetery, 'BLOK-A', 'Blok A', $capacity, $actor->id, 'admin');

        return [$cemetery, $block];
    }

    private function firstPlot(CemeteryBlock $block): GravePlot
    {
        return $block->plots()->orderBy('slot')->firstOrFail();
    }

    /**
     * A real order anchored to `$cemetery` through its booking draft —
     * the same `bookingDraft.cemetery_id` path `ReservePlotAction` reads.
     *
     * `product_type` and `status` are NOT NULL columns on `orders` with no
     * defaults (`2026_08_12_100000_create_orders_table.php`), so both are
     * set explicitly here — the brief's sample omitted them.
     */
    private function orderForCemetery(Cemetery $cemetery): Order
    {
        $draft = BookingDraft::query()->create([
            'cemetery_id' => $cemetery->getKey(),
        ]);

        return Order::query()->create([
            'reference' => 'ORD-'.Str::upper(Str::random(8)),
            'product_type' => ProductType::PRE_NEED_PLOT_PURCHASE->value,
            'status' => OrderStatus::DIVERIFIKASI->value,
            'booking_draft_id' => $draft->getKey(),
        ]);
    }

    // -----------------------------------------------------------------
    // Happy paths
    // -----------------------------------------------------------------

    public function test_marking_a_plot_occupied_flips_the_state_and_writes_an_audit_row(): void
    {
        $admin = $this->freshAdmin();
        [$cemetery, $block] = $this->granularCemetery($admin);
        $plot = $this->firstPlot($block);

        $this->assertSame(PlotState::AVAILABLE, $plot->plot_state);

        Livewire::actingAs($admin)
            ->test(AdminPlotFloorMap::class)
            ->set('cemeteryId', (string) $cemetery->getKey())
            ->call('openPlot', (string) $plot->getKey())
            ->call('markPlotState', PlotState::OCCUPIED)
            ->assertOk();

        $this->assertSame(PlotState::OCCUPIED, $plot->fresh()?->plot_state);

        $this->assertTrue(
            AuditEvent::query()
                ->where('action', PlotInventoryAuditActions::GRAVE_PLOT_STATE_CHANGED)
                ->where('subject_id', (string) $plot->getKey())
                ->exists(),
            'The override must write a GRAVE_PLOT_STATE_CHANGED audit row.',
        );
    }

    public function test_marking_a_plot_under_maintenance_then_available_round_trips(): void
    {
        $admin = $this->freshAdmin();
        [$cemetery, $block] = $this->granularCemetery($admin);
        $plot = $this->firstPlot($block);

        $component = Livewire::actingAs($admin)
            ->test(AdminPlotFloorMap::class)
            ->set('cemeteryId', (string) $cemetery->getKey())
            ->call('openPlot', (string) $plot->getKey())
            ->call('markPlotState', PlotState::MAINTENANCE);

        $this->assertSame(PlotState::MAINTENANCE, $plot->fresh()?->plot_state);

        $component->call('markPlotState', PlotState::AVAILABLE);

        $this->assertSame(PlotState::AVAILABLE, $plot->fresh()?->plot_state);
    }

    // -----------------------------------------------------------------
    // The from-state guard (finding I2) — enforced at wire level
    // -----------------------------------------------------------------

    public function test_marking_an_available_plot_available_again_is_refused_without_a_write(): void
    {
        $admin = $this->freshAdmin();
        [$cemetery, $block] = $this->granularCemetery($admin);
        $plot = $this->firstPlot($block);

        Livewire::actingAs($admin)
            ->test(AdminPlotFloorMap::class)
            ->set('cemeteryId', (string) $cemetery->getKey())
            ->call('openPlot', (string) $plot->getKey())
            ->call('markPlotState', PlotState::AVAILABLE);

        $this->assertSame(PlotState::AVAILABLE, $plot->fresh()?->plot_state);
        $this->assertSame(
            0,
            AuditEvent::query()
                ->where('action', PlotInventoryAuditActions::GRAVE_PLOT_STATE_CHANGED)
                ->where('subject_id', (string) $plot->getKey())
                ->count(),
            'A refused override must write nothing at all, audit row included.',
        );
    }

    public function test_a_reserved_plot_can_never_be_freed_by_the_override(): void
    {
        $admin = $this->freshAdmin();
        [$cemetery, $block] = $this->granularCemetery($admin);
        $plot = $this->firstPlot($block);
        $plot->update(['plot_state' => PlotState::RESERVED]);

        Livewire::actingAs($admin)
            ->test(AdminPlotFloorMap::class)
            ->set('cemeteryId', (string) $cemetery->getKey())
            ->call('openPlot', (string) $plot->getKey())
            ->call('markPlotState', PlotState::AVAILABLE);

        $this->assertSame(
            PlotState::RESERVED,
            $plot->fresh()?->plot_state,
            'A reserved plot is owned by its reservation and must never be freed behind it.',
        );
    }

    public function test_an_unknown_target_state_is_refused_without_a_write(): void
    {
        $admin = $this->freshAdmin();
        [$cemetery, $block] = $this->granularCemetery($admin);
        $plot = $this->firstPlot($block);

        Livewire::actingAs($admin)
            ->test(AdminPlotFloorMap::class)
            ->set('cemeteryId', (string) $cemetery->getKey())
            ->call('openPlot', (string) $plot->getKey())
            ->call('markPlotState', 'demolished')
            ->assertOk();

        $this->assertSame(PlotState::AVAILABLE, $plot->fresh()?->plot_state);
    }

    // -----------------------------------------------------------------
    // Authorization and freshness
    // -----------------------------------------------------------------

    public function test_a_stale_actor_is_refused_before_any_write(): void
    {
        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::ADMIN);
        $this->actingAs($user);
        $this->seedActorSession($user, CarbonImmutable::now()->subYear());

        [$cemetery, $block] = $this->granularCemetery($user);
        $plot = $this->firstPlot($block);

        Livewire::actingAs($user)
            ->test(AdminPlotFloorMap::class)
            ->set('cemeteryId', (string) $cemetery->getKey())
            ->call('openPlot', (string) $plot->getKey())
            ->call('markPlotState', PlotState::OCCUPIED);

        $this->assertSame(
            PlotState::AVAILABLE,
            $plot->fresh()?->plot_state,
            'A stale actor must be sent to re-authentication, not allowed to write.',
        );
    }

    /**
     * The Phase D ruling: write authorization is NOT widened to
     * `cemetery_operator`. A bare cemetery-operator gets a complete
     * READ-ONLY map — the cell modal offers no override buttons and a
     * direct wire call writes nothing.
     */
    public function test_a_bare_cemetery_operator_gets_a_read_only_map(): void
    {
        $setupAdmin = $this->freshAdmin();
        [$cemetery, $block] = $this->granularCemetery($setupAdmin);
        $plot = $this->firstPlot($block);

        $operator = User::factory()->create();
        $this->grantRoleTo($operator, ActorRole::CEMETERY_OPERATOR);
        ScopeAssignment::query()->create([
            'actor_identifier' => (string) $operator->id,
            'entity_type' => ScopeEntityType::CEMETERY,
            'entity_id' => (string) $cemetery->getKey(),
        ]);
        $this->actingAs($operator);
        $this->seedActorSession($operator, CarbonImmutable::now());
        $this->forgetResolvedActorContext();
        $this->app->forgetScopedInstances();

        Livewire::actingAs($operator)
            ->test(OperatorPlotFloorMap::class)
            ->assertSee('BLOK-A')
            ->assertDontSee('Tandai Terisi')
            ->call('openPlot', (string) $plot->getKey())
            ->call('markPlotState', PlotState::OCCUPIED);

        $this->assertSame(PlotState::AVAILABLE, $plot->fresh()?->plot_state);
    }

    public function test_a_plot_outside_the_selected_cemetery_cannot_be_overridden(): void
    {
        $admin = $this->freshAdmin();
        [$cemetery] = $this->granularCemetery($admin);

        $other = Cemetery::factory()->create(['plot_tracking_mode' => PlotTrackingMode::GRANULAR]);
        $otherBlock = app(CreateCemeteryBlock::class)($other, 'BLOK-Z', 'Blok Z', 2, $admin->id, 'admin');
        $foreignPlot = $this->firstPlot($otherBlock);

        Livewire::actingAs($admin)
            ->test(AdminPlotFloorMap::class)
            ->set('cemeteryId', (string) $cemetery->getKey())
            ->set('activePlotId', (string) $foreignPlot->getKey())
            ->call('markPlotState', PlotState::OCCUPIED);

        $this->assertSame(PlotState::AVAILABLE, $foreignPlot->fresh()?->plot_state);
    }

    // -----------------------------------------------------------------
    // The shipped table must keep behaving identically
    // -----------------------------------------------------------------

    public function test_the_shared_override_path_reports_the_same_from_states_the_table_documented(): void
    {
        $overrides = PlotStateOverrides::class;

        $this->assertSame(
            [PlotState::MAINTENANCE, PlotState::OCCUPIED],
            $overrides::fromStates(PlotState::AVAILABLE),
        );
        $this->assertSame(
            [PlotState::AVAILABLE, PlotState::RESERVED, PlotState::MAINTENANCE],
            $overrides::fromStates(PlotState::OCCUPIED),
        );
        $this->assertSame(
            [PlotState::AVAILABLE, PlotState::RESERVED, PlotState::OCCUPIED],
            $overrides::fromStates(PlotState::MAINTENANCE),
        );
        $this->assertSame([], $overrides::fromStates('demolished'));
    }

    // -----------------------------------------------------------------
    // Order-linked entry mode
    // -----------------------------------------------------------------

    public function test_the_order_linked_mode_offers_and_performs_a_reservation(): void
    {
        $admin = $this->freshAdmin();
        [$cemetery, $block] = $this->granularCemetery($admin);
        $plot = $this->firstPlot($block);
        $order = $this->orderForCemetery($cemetery);

        Livewire::actingAs($admin)
            ->withQueryParams(['order_id' => (string) $order->getKey()])
            ->test(AdminPlotFloorMap::class)
            ->assertSet('cemeteryId', (string) $cemetery->getKey())
            ->call('openPlot', (string) $plot->getKey())
            ->assertSee('Reservasi untuk pesanan #'.$order->reference)
            ->call('reserveForOrder')
            ->assertOk();

        $this->assertSame(PlotState::RESERVED, $plot->fresh()?->plot_state);

        $reservation = PlotReservation::activeForOrder($order->fresh());
        $this->assertNotNull($reservation);
        $this->assertSame(PlotReservationState::HELD, $reservation->state);
        $this->assertSame((string) $plot->getKey(), (string) $reservation->plot_id);
    }

    public function test_without_an_order_id_the_reservation_offer_is_absent_and_the_call_writes_nothing(): void
    {
        $admin = $this->freshAdmin();
        [$cemetery, $block] = $this->granularCemetery($admin);
        $plot = $this->firstPlot($block);

        Livewire::actingAs($admin)
            ->test(AdminPlotFloorMap::class)
            ->set('cemeteryId', (string) $cemetery->getKey())
            ->call('openPlot', (string) $plot->getKey())
            ->assertDontSee('Reservasi untuk pesanan')
            ->call('reserveForOrder')
            ->assertOk();

        $this->assertSame(PlotState::AVAILABLE, $plot->fresh()?->plot_state);
        $this->assertSame(0, PlotReservation::query()->count());
    }

    public function test_a_malformed_order_id_is_ignored_instead_of_erroring(): void
    {
        $admin = $this->freshAdmin();
        [$cemetery, $block] = $this->granularCemetery($admin);
        $plot = $this->firstPlot($block);

        Livewire::actingAs($admin)
            ->withQueryParams(['order_id' => 'not-a-uuid'])
            ->test(AdminPlotFloorMap::class)
            ->set('cemeteryId', (string) $cemetery->getKey())
            ->call('openPlot', (string) $plot->getKey())
            ->call('reserveForOrder')
            ->assertOk();

        $this->assertSame(PlotState::AVAILABLE, $plot->fresh()?->plot_state);
    }

    /**
     * The order is only ever addressable through the SELECTED cemetery,
     * which is itself scoped. An order belonging to another cemetery
     * resolves to null, so an operator can never reserve one cemetery's
     * plot for another cemetery's order.
     */
    public function test_an_order_from_another_cemetery_does_not_resolve(): void
    {
        $admin = $this->freshAdmin();
        [$cemetery, $block] = $this->granularCemetery($admin);
        $plot = $this->firstPlot($block);

        $otherCemetery = Cemetery::factory()->create(['plot_tracking_mode' => PlotTrackingMode::GRANULAR]);
        $foreignOrder = $this->orderForCemetery($otherCemetery);

        Livewire::actingAs($admin)
            ->test(AdminPlotFloorMap::class)
            ->set('cemeteryId', (string) $cemetery->getKey())
            ->set('orderId', (string) $foreignOrder->getKey())
            ->call('openPlot', (string) $plot->getKey())
            ->call('reserveForOrder');

        $this->assertSame(PlotState::AVAILABLE, $plot->fresh()?->plot_state);
        $this->assertSame(0, PlotReservation::query()->count());
    }

    public function test_reserving_an_unavailable_plot_surfaces_the_domain_refusal_without_a_write(): void
    {
        $admin = $this->freshAdmin();
        [$cemetery, $block] = $this->granularCemetery($admin);
        $plot = $this->firstPlot($block);
        $plot->update(['plot_state' => PlotState::OCCUPIED]);
        $order = $this->orderForCemetery($cemetery);

        Livewire::actingAs($admin)
            ->withQueryParams(['order_id' => (string) $order->getKey()])
            ->test(AdminPlotFloorMap::class)
            ->call('openPlot', (string) $plot->getKey())
            ->call('reserveForOrder')
            ->assertOk();

        $this->assertSame(PlotState::OCCUPIED, $plot->fresh()?->plot_state);
        $this->assertSame(0, PlotReservation::query()->count());
    }

    // -----------------------------------------------------------------
    // Reservation lifecycle on a reserved cell
    // -----------------------------------------------------------------

    /**
     * @return array{0: Cemetery, 1: GravePlot, 2: Order}
     */
    private function reservedPlot(User $admin): array
    {
        [$cemetery, $block] = $this->granularCemetery($admin);
        $plot = $this->firstPlot($block);
        $order = $this->orderForCemetery($cemetery);

        app(ReservePlot::class)(
            $plot,
            $order,
            (string) $admin->id,
            'admin',
        );

        return [$cemetery, $plot->fresh(), $order];
    }

    public function test_a_held_reservation_can_be_confirmed_from_the_map(): void
    {
        $admin = $this->freshAdmin();
        [$cemetery, $plot, $order] = $this->reservedPlot($admin);

        Livewire::actingAs($admin)
            ->test(AdminPlotFloorMap::class)
            ->set('cemeteryId', (string) $cemetery->getKey())
            ->call('openPlot', (string) $plot->getKey())
            ->assertSee('Konfirmasi Reservasi')
            ->call('runReservationAction', 'confirm')
            ->assertOk();

        $this->assertSame(
            PlotReservationState::CONFIRMED,
            PlotReservation::activeForPlot($plot)?->state,
        );
        $this->assertSame(
            PlotState::RESERVED,
            $plot->fresh()?->plot_state,
            'Confirming does not free the plot — a confirmed reservation is still the claim.',
        );
    }

    public function test_a_held_reservation_can_be_released_and_the_plot_returns_to_available(): void
    {
        $admin = $this->freshAdmin();
        [$cemetery, $plot] = $this->reservedPlot($admin);

        Livewire::actingAs($admin)
            ->test(AdminPlotFloorMap::class)
            ->set('cemeteryId', (string) $cemetery->getKey())
            ->call('openPlot', (string) $plot->getKey())
            ->call('runReservationAction', 'release')
            ->assertOk();

        $this->assertNull(PlotReservation::activeForPlot($plot));
        $this->assertSame(PlotState::AVAILABLE, $plot->fresh()?->plot_state);
    }

    public function test_a_held_reservation_can_be_expired(): void
    {
        $admin = $this->freshAdmin();
        [$cemetery, $plot] = $this->reservedPlot($admin);

        Livewire::actingAs($admin)
            ->test(AdminPlotFloorMap::class)
            ->set('cemeteryId', (string) $cemetery->getKey())
            ->call('openPlot', (string) $plot->getKey())
            ->call('runReservationAction', 'expire')
            ->assertOk();

        $this->assertNull(PlotReservation::activeForPlot($plot));
        $this->assertSame(PlotState::AVAILABLE, $plot->fresh()?->plot_state);
    }

    public function test_expire_is_not_offered_once_a_reservation_is_confirmed(): void
    {
        $admin = $this->freshAdmin();
        [$cemetery, $plot] = $this->reservedPlot($admin);

        app(ConfirmPlotReservation::class)(
            PlotReservation::activeForPlot($plot),
            (string) $admin->id,
            'admin',
        );

        $component = Livewire::actingAs($admin)
            ->test(AdminPlotFloorMap::class)
            ->set('cemeteryId', (string) $cemetery->getKey())
            ->call('openPlot', (string) $plot->getKey());

        $component->assertSee('Lepaskan Reservasi')
            ->assertDontSee('Kedaluwarsakan Reservasi')
            ->assertDontSee('Konfirmasi Reservasi');

        // And the wire call is refused too, not just hidden.
        $component->call('runReservationAction', 'expire');

        $this->assertSame(
            PlotReservationState::CONFIRMED,
            PlotReservation::activeForPlot($plot)?->state,
        );
    }

    public function test_an_available_plot_offers_no_reservation_actions(): void
    {
        $admin = $this->freshAdmin();
        [$cemetery, $block] = $this->granularCemetery($admin);
        $plot = $this->firstPlot($block);

        Livewire::actingAs($admin)
            ->test(AdminPlotFloorMap::class)
            ->set('cemeteryId', (string) $cemetery->getKey())
            ->call('openPlot', (string) $plot->getKey())
            ->assertDontSee('Konfirmasi Reservasi')
            ->assertDontSee('Lepaskan Reservasi')
            ->call('runReservationAction', 'release')
            ->assertOk();

        $this->assertSame(PlotState::AVAILABLE, $plot->fresh()?->plot_state);
    }

    public function test_an_unknown_reservation_action_key_writes_nothing(): void
    {
        $admin = $this->freshAdmin();
        [$cemetery, $plot] = $this->reservedPlot($admin);

        Livewire::actingAs($admin)
            ->test(AdminPlotFloorMap::class)
            ->set('cemeteryId', (string) $cemetery->getKey())
            ->call('openPlot', (string) $plot->getKey())
            ->call('runReservationAction', 'obliterate')
            ->assertOk();

        $this->assertSame(
            PlotReservationState::HELD,
            PlotReservation::activeForPlot($plot)?->state,
        );
    }

    public function test_a_bare_cemetery_operator_cannot_run_a_reservation_action(): void
    {
        $setupAdmin = $this->freshAdmin();
        [$cemetery, $plot] = $this->reservedPlot($setupAdmin);

        $operator = User::factory()->create();
        $this->grantRoleTo($operator, ActorRole::CEMETERY_OPERATOR);
        ScopeAssignment::query()->create([
            'actor_identifier' => (string) $operator->id,
            'entity_type' => ScopeEntityType::CEMETERY,
            'entity_id' => (string) $cemetery->getKey(),
        ]);
        $this->actingAs($operator);
        $this->seedActorSession($operator, CarbonImmutable::now());
        $this->forgetResolvedActorContext();
        $this->app->forgetScopedInstances();

        Livewire::actingAs($operator)
            ->test(OperatorPlotFloorMap::class)
            ->call('openPlot', (string) $plot->getKey())
            ->call('runReservationAction', 'release');

        $this->assertSame(
            PlotReservationState::HELD,
            PlotReservation::activeForPlot($plot)?->state,
        );
    }
}
