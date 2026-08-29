<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\PlotReservation;

use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Domain\OrderWorkflow\Models\Order;
use App\Domain\OrderWorkflow\OrderStatus;
use App\Domain\OrderWorkflow\ProductType;
use App\Domain\PlotInventory\Models\CemeteryBlock;
use App\Domain\PlotInventory\Models\GravePlot;
use App\Domain\PlotInventory\PlotState;
use App\Domain\PlotReservation\Actions\ConfirmPlotReservation;
use App\Domain\PlotReservation\Actions\ExpirePlotReservation;
use App\Domain\PlotReservation\Actions\ReleasePlotReservation;
use App\Domain\PlotReservation\Actions\ReservePlot;
use App\Domain\PlotReservation\Exceptions\PlotReservationTransitionException;
use App\Domain\PlotReservation\Models\PlotReservation;
use App\Domain\PlotReservation\PlotReservationState;
use App\Platform\Audit\Models\AuditEvent;
use App\Platform\Outbox\Models\OutboxEvent;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class PlotReservationLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private function held(): array
    {
        $cemetery = Cemetery::factory()->create();
        $block = CemeteryBlock::query()->create(['cemetery_id' => $cemetery->getKey(), 'code' => 'BLOK-A', 'name' => 'Blok A', 'capacity' => 1]);
        $plot = GravePlot::query()->create(['block_id' => $block->getKey(), 'slot' => '001', 'plot_state' => PlotState::AVAILABLE]);
        $order = Order::query()->create([
            'reference' => 'MK-2026-'.Str::upper(Str::random(8)),
            'product_type' => ProductType::AT_NEED_SERVICE_ORDER,
            'status' => OrderStatus::DIVERIFIKASI,
        ]);
        $reservation = app(ReservePlot::class)($plot, $order, 'user:1', 'operator');

        return [$plot, $order, $reservation];
    }

    private function stateChangedEvent(PlotReservation $reservation): OutboxEvent
    {
        // The transition's own event — `ReservePlot` already emitted one
        // for the held row, so filter by the new row's idempotency key.
        return OutboxEvent::query()
            ->where('event_name', 'plot_reservation.state_changed.v1')
            ->where('idempotency_key', "plot_reservation:{$reservation->getKey()}")
            ->sole();
    }

    public function test_confirm_keeps_plot_reserved(): void
    {
        [$plot, , $reservation] = $this->held();
        $confirmed = app(ConfirmPlotReservation::class)($reservation, 'user:1', 'operator');
        $this->assertSame(PlotReservationState::CONFIRMED, $confirmed->state);
        $this->assertSame(PlotState::RESERVED, $plot->fresh()->plot_state);
        $this->assertDatabaseHas('audit_events', ['action' => 'PLOT_RESERVATION_CONFIRMED']);

        // Finding A's regression: the outbox payload's `plot_id` must be
        // the PLOT's key — never the reservation row's key.
        $this->assertDatabaseHas('outbox_events', ['event_name' => 'plot_reservation.state_changed.v1']);
        $event = $this->stateChangedEvent($confirmed);
        $this->assertSame($plot->getKey(), $event->payload['plot_id']);
        $this->assertSame(PlotReservationState::HELD, $event->payload['from_state']);
        $this->assertSame(PlotReservationState::CONFIRMED, $event->payload['to_state']);
    }

    public function test_release_restores_availability(): void
    {
        [$plot, , $reservation] = $this->held();
        $released = app(ReleasePlotReservation::class)($reservation, 'user:1', 'operator');
        $this->assertSame(PlotReservationState::RELEASED, $released->state);
        $this->assertSame(PlotState::AVAILABLE, $plot->fresh()->plot_state);
        $this->assertDatabaseHas('audit_events', ['action' => 'PLOT_RESERVATION_RELEASED']);

        $this->assertDatabaseHas('outbox_events', ['event_name' => 'plot_reservation.state_changed.v1']);
        $event = $this->stateChangedEvent($released);
        $this->assertSame($plot->getKey(), $event->payload['plot_id']);
        $this->assertContains($event->payload['from_state'], [PlotReservationState::HELD, PlotReservationState::CONFIRMED]);
        $this->assertSame(PlotReservationState::RELEASED, $event->payload['to_state']);
    }

    public function test_expire_restores_availability(): void
    {
        [$plot, , $reservation] = $this->held();
        $expired = app(ExpirePlotReservation::class)($reservation, 'user:1', 'operator');
        $this->assertSame(PlotReservationState::EXPIRED, $expired->state);
        $this->assertSame(PlotState::AVAILABLE, $plot->fresh()->plot_state);
        $this->assertDatabaseHas('audit_events', ['action' => 'PLOT_RESERVATION_EXPIRED']);

        $this->assertDatabaseHas('outbox_events', ['event_name' => 'plot_reservation.state_changed.v1']);
        $event = $this->stateChangedEvent($expired);
        $this->assertSame($plot->getKey(), $event->payload['plot_id']);
        $this->assertSame(PlotReservationState::HELD, $event->payload['from_state']);
        $this->assertSame(PlotReservationState::EXPIRED, $event->payload['to_state']);
    }

    public function test_terminal_reservation_refuses_further_transitions(): void
    {
        [$plot, , $reservation] = $this->held();
        $expired = app(ExpirePlotReservation::class)($reservation, 'user:1', 'operator');
        $this->assertSame(PlotState::AVAILABLE, $plot->fresh()->plot_state);
        $this->expectException(PlotReservationTransitionException::class);
        app(ConfirmPlotReservation::class)($expired, 'user:1', 'operator');
    }

    public function test_second_transition_on_same_chain_is_refused(): void
    {
        // Finding B's regression, in its deterministic (sequential) form:
        // after confirm commits, the chain's latest row is `confirmed`,
        // so a second confirm on the same plot must be refused — the same
        // state-assert failure a LOSING concurrent transition would hit
        // after the winner commits.
        [$plot, , $reservation] = $this->held();
        $confirmed = app(ConfirmPlotReservation::class)($reservation, 'user:1', 'operator');
        $this->assertSame(PlotState::RESERVED, $plot->fresh()->plot_state);
        $this->expectException(PlotReservationTransitionException::class);
        app(ConfirmPlotReservation::class)($confirmed, 'user:1', 'operator');
    }

    /**
     * Finding C1's regression: an admin override ('Tandai Terisi' →
     * occupied) on a reserved plot must survive release — the chain is
     * still closed (the `released` row is appended, the order loses its
     * active hold) but `plot_state` is NOT flipped back to `available`,
     * and the audit reason records the divergence. Before the fix the
     * unconditional flip destroyed the override (occupied → available =
     * a buried plot becomes reservable).
     */
    public function test_release_preserves_an_admin_override_on_the_plot(): void
    {
        [$plot, $order, $reservation] = $this->held();

        $plot->update(['plot_state' => PlotState::OCCUPIED]);

        $released = app(ReleasePlotReservation::class)($reservation, 'user:1', 'operator');

        $this->assertSame(PlotState::OCCUPIED, $plot->fresh()->plot_state);
        $this->assertSame(PlotReservationState::RELEASED, $released->state);
        $this->assertNull(PlotReservation::activeForOrder($order));
        $this->assertDatabaseHas('audit_events', ['action' => 'PLOT_RESERVATION_RELEASED']);

        $event = $this->stateChangedEvent($released);
        $this->assertSame($plot->getKey(), $event->payload['plot_id']);
        $this->assertSame(PlotReservationState::RELEASED, $event->payload['to_state']);

        $audit = AuditEvent::query()
            ->where('action', 'PLOT_RESERVATION_RELEASED')
            ->where('subject_id', $released->getKey())
            ->sole();
        $this->assertStringContainsString('plot state diverged from reserved (override preserved)', $audit->reason);
    }

    /**
     * Finding C1's regression for the expiry hop: a maintenance override
     * behind a held chain survives expire the same way release preserves
     * it — chain closed, `plot_state` untouched, divergence noted.
     */
    public function test_expire_preserves_an_admin_override_on_the_plot(): void
    {
        [$plot, $order, $reservation] = $this->held();

        $plot->update(['plot_state' => PlotState::MAINTENANCE]);

        $expired = app(ExpirePlotReservation::class)($reservation, 'user:1', 'operator');

        $this->assertSame(PlotState::MAINTENANCE, $plot->fresh()->plot_state);
        $this->assertSame(PlotReservationState::EXPIRED, $expired->state);
        $this->assertNull(PlotReservation::activeForOrder($order));
        $this->assertDatabaseHas('audit_events', ['action' => 'PLOT_RESERVATION_EXPIRED']);

        $event = $this->stateChangedEvent($expired);
        $this->assertSame($plot->getKey(), $event->payload['plot_id']);
        $this->assertSame(PlotReservationState::EXPIRED, $event->payload['to_state']);

        $audit = AuditEvent::query()
            ->where('action', 'PLOT_RESERVATION_EXPIRED')
            ->where('subject_id', $expired->getKey())
            ->sole();
        $this->assertStringContainsString('plot state diverged from reserved (override preserved)', $audit->reason);
    }

    public function test_active_states_is_the_closed_held_confirmed_pair(): void
    {
        $this->assertSame(['held', 'confirmed'], PlotReservationState::ACTIVE_STATES);
    }

    public function test_incumbent_of_returns_the_head_row_when_it_is_active(): void
    {
        $reservation = new PlotReservation(['state' => PlotReservationState::HELD]);

        $this->assertSame($reservation, PlotReservation::incumbentOf($reservation));
    }

    public function test_incumbent_of_returns_null_for_a_released_head_row(): void
    {
        $reservation = new PlotReservation(['state' => PlotReservationState::RELEASED]);

        $this->assertNull(PlotReservation::incumbentOf($reservation));
    }

    public function test_incumbent_of_returns_null_for_a_null_head_row(): void
    {
        $this->assertNull(PlotReservation::incumbentOf(null));
    }

    public function test_plot_reservations_relation_returns_the_chain_newest_first(): void
    {
        $cemetery = Cemetery::factory()->create();
        $block = CemeteryBlock::query()->create([
            'cemetery_id' => $cemetery->id,
            'code' => 'BLOK-C1',
            'name' => 'Blok C1',
            'capacity' => 5,
            'is_active' => true,
        ]);
        $plot = GravePlot::query()->create([
            'block_id' => $block->id,
            'slot' => 'C1-001',
            'plot_state' => PlotState::AVAILABLE,
        ]);
        $order = Order::query()->create([
            'reference' => 'MK-2026-'.Str::upper(Str::random(8)),
            'product_type' => ProductType::AT_NEED_SERVICE_ORDER->value,
            'status' => OrderStatus::DIVERIFIKASI->value,
        ]);

        $held = PlotReservation::query()->create([
            'plot_id' => $plot->id,
            'order_id' => $order->id,
            'state' => PlotReservationState::HELD,
            'reserved_by_ref' => '1',
            'reserved_at' => CarbonImmutable::now()->subHour(),
            'created_at' => CarbonImmutable::now()->subHour(),
            'updated_at' => CarbonImmutable::now()->subHour(),
        ]);
        $released = PlotReservation::query()->create([
            'plot_id' => $plot->id,
            'order_id' => $order->id,
            'state' => PlotReservationState::RELEASED,
            'reserved_by_ref' => '1',
            'released_at' => CarbonImmutable::now(),
            'created_at' => CarbonImmutable::now(),
            'updated_at' => CarbonImmutable::now(),
        ]);

        $chain = $order->fresh()->plotReservations;

        $this->assertSame((string) $released->id, (string) $chain->first()->id);
        $this->assertSame((string) $held->id, (string) $chain->last()->id);
        $this->assertNull(PlotReservation::incumbentOf($chain->first()));
    }
}
