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

    public function test_confirm_keeps_plot_reserved(): void
    {
        [$plot, , $reservation] = $this->held();
        $confirmed = app(ConfirmPlotReservation::class)($reservation, 'user:1', 'operator');
        $this->assertSame(PlotReservationState::CONFIRMED, $confirmed->state);
        $this->assertSame(PlotState::RESERVED, $plot->fresh()->plot_state);
        $this->assertDatabaseHas('audit_events', ['action' => 'PLOT_RESERVATION_CONFIRMED']);
    }

    public function test_release_restores_availability(): void
    {
        [$plot, , $reservation] = $this->held();
        $released = app(ReleasePlotReservation::class)($reservation, 'user:1', 'operator');
        $this->assertSame(PlotReservationState::RELEASED, $released->state);
        $this->assertSame(PlotState::AVAILABLE, $plot->fresh()->plot_state);
        $this->assertDatabaseHas('audit_events', ['action' => 'PLOT_RESERVATION_RELEASED']);
    }

    public function test_expire_restores_availability(): void
    {
        [$plot, , $reservation] = $this->held();
        $expired = app(ExpirePlotReservation::class)($reservation, 'user:1', 'operator');
        $this->assertSame(PlotReservationState::EXPIRED, $expired->state);
        $this->assertSame(PlotState::AVAILABLE, $plot->fresh()->plot_state);
        $this->assertDatabaseHas('audit_events', ['action' => 'PLOT_RESERVATION_EXPIRED']);
    }

    public function test_terminal_reservation_refuses_further_transitions(): void
    {
        [$plot, , $reservation] = $this->held();
        app(ExpirePlotReservation::class)($reservation, 'user:1', 'operator');
        $latest = PlotReservation::query()->where('plot_id', $plot->getKey())->latest()->first();
        $this->expectException(PlotReservationTransitionException::class);
        app(ConfirmPlotReservation::class)($latest, 'user:1', 'operator');
    }
}
