<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\PlotReservation;

use App\Domain\CemeteryDirectory\CemeteryPublicationStatus;
use App\Domain\CemeteryDirectory\CemeteryType;
use App\Domain\CemeteryDirectory\LaunchCityCode;
use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Domain\OrderWorkflow\Models\Order;
use App\Domain\OrderWorkflow\OrderStatus;
use App\Domain\OrderWorkflow\ProductType;
use App\Domain\PlotInventory\Models\CemeteryBlock;
use App\Domain\PlotInventory\Models\GravePlot;
use App\Domain\PlotInventory\PlotState;
use App\Domain\PlotReservation\Actions\ReservePlot;
use App\Domain\PlotReservation\Exceptions\PlotNotAvailableException;
use App\Domain\PlotReservation\Exceptions\PlotReservationConflictException;
use App\Domain\PlotReservation\Models\PlotReservation;
use App\Domain\PlotReservation\PlotReservationState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ReservePlotTest extends TestCase
{
    use RefreshDatabase;

    private function cemetery(): Cemetery
    {
        // `Cemetery::factory()` does not exist (no factory class, no
        // `HasFactory` on the model — the plan flagged this as a drift
        // risk to resolve at implementation time), so the fixture is a
        // direct create against the model's saving guards: `type`,
        // `publication_status`, and `city` are the three values the
        // guard validates, `name`/`slug`/`address` are the NOT NULL
        // columns beyond them.
        return Cemetery::query()->create([
            'type' => CemeteryType::TPU,
            'publication_status' => CemeteryPublicationStatus::DRAFT,
            'name' => 'TPU Uji Coba',
            'slug' => 'tpu-uji-coba-'.Str::lower(Str::random(6)),
            'city' => LaunchCityCode::JAKARTA,
            'address' => 'Jl. Contoh No. 1',
        ]);
    }

    private function plot(PlotState $state = PlotState::AVAILABLE): GravePlot
    {
        $cemetery = $this->cemetery();
        $block = CemeteryBlock::query()->create(['cemetery_id' => $cemetery->getKey(), 'code' => 'BLOK-A', 'name' => 'Blok A', 'capacity' => 1]);

        return GravePlot::query()->create(['block_id' => $block->getKey(), 'slot' => '001', 'plot_state' => $state->value]);
    }

    private function order(): Order
    {
        return Order::query()->create([
            'reference' => 'MK-2026-'.Str::upper(Str::random(8)),
            'product_type' => ProductType::AT_NEED_SERVICE_ORDER->value,
            'status' => OrderStatus::DIVERIFIKASI->value,
        ]);
    }

    public function test_reserves_an_available_plot(): void
    {
        $plot = $this->plot();
        $order = $this->order();

        $reservation = app(ReservePlot::class)($plot, $order, 'user:1', 'operator');

        $this->assertSame(PlotReservationState::HELD, $reservation->state);
        $this->assertSame(PlotState::RESERVED, $plot->fresh()->plot_state);
        $this->assertDatabaseHas('plot_reservations', ['plot_id' => $plot->getKey(), 'order_id' => $order->getKey(), 'state' => 'held']);
        $this->assertDatabaseHas('audit_events', ['action' => 'PLOT_RESERVATION_CREATED']);
        $this->assertDatabaseHas('outbox_events', ['event_name' => 'plot_reservation.state_changed.v1']);
    }

    public function test_order_idempotency_returns_incumbent(): void
    {
        $plot = $this->plot();
        $order = $this->order();
        $first = app(ReservePlot::class)($plot, $order, 'user:1', 'operator');

        $second = app(ReservePlot::class)($plot, $order, 'user:1', 'operator');

        $this->assertSame($first->getKey(), $second->getKey());
        $this->assertSame(1, PlotReservation::query()->count());
    }

    public function test_occupied_plot_is_refused(): void
    {
        $plot = $this->plot(PlotState::OCCUPIED);
        $order = $this->order();

        $this->expectException(PlotNotAvailableException::class);
        app(ReservePlot::class)($plot, $order, 'user:1', 'operator');
    }

    /**
     * The classifier test (plan Step 4): the plot is deliberately left
     * `available` while a HELD row already exists for it — a direct
     * insert that simulates the race window in which a second session
     * passed the `available` assert before the first committed. The
     * plot-state assert therefore passes and the flow reaches the
     * INSERT, where `plot_reservations_active_hold` fires and the
     * narrow classifier translates the `QueryException` into the domain
     * conflict. On SQLite this exercises the `unique` +
     * `plot_reservations.plot_id` signal; the index-name signal is the
     * PostgreSQL path, exercised by CI.
     */
    public function test_duplicate_active_hold_is_classified_as_conflict(): void
    {
        $plot = $this->plot();
        $order = $this->order();
        $otherOrder = $this->order();

        PlotReservation::query()->create([
            'plot_id' => $plot->getKey(),
            'order_id' => $otherOrder->getKey(),
            'state' => PlotReservationState::HELD,
            'reserved_by_ref' => 'user:0',
            'reserved_at' => now(),
        ]);

        $this->expectException(PlotReservationConflictException::class);
        app(ReservePlot::class)($plot, $order, 'user:1', 'operator');
    }
}
