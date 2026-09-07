<?php

declare(strict_types=1);

namespace Tests\Feature\OrderWorkflow;

use App\Domain\CemeteryDirectory\CemeteryPublicationStatus;
use App\Domain\CemeteryDirectory\CemeteryType;
use App\Domain\CemeteryDirectory\LaunchCityCode;
use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Domain\OrderWorkflow\Actions\CancelOrder;
use App\Domain\OrderWorkflow\Actions\ExpireOrder;
use App\Domain\OrderWorkflow\Actions\RecordOrderStatusChange;
use App\Domain\OrderWorkflow\Actions\RejectOrder;
use App\Domain\OrderWorkflow\Models\Order;
use App\Domain\OrderWorkflow\OrderStatus;
use App\Domain\OrderWorkflow\ProductType;
use App\Domain\PlotInventory\Models\CemeteryBlock;
use App\Domain\PlotInventory\Models\GravePlot;
use App\Domain\PlotInventory\PlotState;
use App\Domain\PlotReservation\Actions\ReservePlot;
use App\Domain\PlotReservation\Models\PlotReservation;
use App\Domain\PlotReservation\PlotReservationState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * UNBUILT-01 remediation (Batch 2C,
 * `docs/superpowers/plans/2026-09-06-batch2c-plot-reservation-release.md`):
 * an order-anchored plot reservation — the shape
 * `ConvertDraftHoldToOrderReservation` produces, `order_id` set and no
 * `expires_at` — must be released when its order lands on a terminal,
 * non-completed status (`DIBATALKAN`, `DITOLAK`, `KEDALUWARSA`). Before this
 * fix, `CancelOrder`/`RejectOrder`/`ExpireOrder` were pure pass-throughs to
 * `RecordOrderStatusChange` with no reservation-release logic anywhere in
 * that path, leaving the plot claimed forever.
 */
final class OrderTerminalTransitionReleasesPlotReservationTest extends TestCase
{
    use RefreshDatabase;

    private function makePlot(): GravePlot
    {
        $cemetery = Cemetery::query()->create([
            'type' => CemeteryType::TPU,
            'publication_status' => CemeteryPublicationStatus::DRAFT,
            'name' => 'TPU Uji Coba',
            'slug' => 'tpu-uji-coba-'.Str::lower(Str::random(6)),
            'city' => LaunchCityCode::JAKARTA,
            'address' => 'Jl. Contoh No. 1',
        ]);
        $block = CemeteryBlock::query()->create(['cemetery_id' => $cemetery->getKey(), 'code' => 'BLOK-A', 'name' => 'Blok A', 'capacity' => 1]);

        return GravePlot::query()->create(['block_id' => $block->getKey(), 'slot' => '001', 'plot_state' => 'available']);
    }

    private function makeOrder(OrderStatus $status = OrderStatus::MASUK): Order
    {
        return Order::query()->create([
            'reference' => 'MK-2026-'.Str::upper(Str::random(8)),
            'product_type' => ProductType::AT_NEED_SERVICE_ORDER->value,
            'status' => $status->value,
        ]);
    }

    /**
     * Builds an order-anchored reservation with the exact shape
     * `ConvertDraftHoldToOrderReservation` produces: `order_id` set,
     * `booking_draft_id` null, no `expires_at`, state `held`.
     */
    private function reserveForOrder(GravePlot $plot, Order $order): PlotReservation
    {
        return (new ReservePlot)($plot, $order, "order:{$order->getKey()}", 'system');
    }

    public function test_cancel_order_releases_its_active_plot_reservation(): void
    {
        $plot = $this->makePlot();
        $order = $this->makeOrder(OrderStatus::MASUK);
        $this->reserveForOrder($plot, $order);

        app(CancelOrder::class)($order, 'actor:admin-1', 'admin', 'pemesan membatalkan');

        $head = PlotReservation::query()
            ->where('plot_id', $plot->getKey())
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();

        $this->assertSame(PlotReservationState::RELEASED, $head->state);
        $this->assertSame(PlotState::AVAILABLE, $plot->fresh()->plot_state);
    }

    public function test_reject_order_releases_its_active_plot_reservation(): void
    {
        $plot = $this->makePlot();
        $order = $this->makeOrder(OrderStatus::MASUK);
        $this->reserveForOrder($plot, $order);

        app(RejectOrder::class)($order, 'actor:admin-1', 'admin', 'dokumen tidak lengkap');

        $head = PlotReservation::query()
            ->where('plot_id', $plot->getKey())
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();

        $this->assertSame(PlotReservationState::RELEASED, $head->state);
        $this->assertSame(PlotState::AVAILABLE, $plot->fresh()->plot_state);
    }

    public function test_expire_order_releases_its_active_plot_reservation(): void
    {
        $plot = $this->makePlot();
        $order = $this->makeOrder(OrderStatus::PENAWARAN_TERKIRIM);
        $this->reserveForOrder($plot, $order);

        app(ExpireOrder::class)($order, 'actor:system', 'system');

        $head = PlotReservation::query()
            ->where('plot_id', $plot->getKey())
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();

        $this->assertSame(PlotReservationState::RELEASED, $head->state);
        $this->assertSame(PlotState::AVAILABLE, $plot->fresh()->plot_state);
    }

    public function test_release_respects_plot_state_divergence_from_an_admin_override(): void
    {
        $plot = $this->makePlot();
        $order = $this->makeOrder(OrderStatus::MASUK);
        $this->reserveForOrder($plot, $order);

        // Admin override: the plot was marked occupied behind the
        // reservation chain (e.g. a burial recorded through another
        // channel) before the order gets cancelled.
        $plot->update(['plot_state' => PlotState::OCCUPIED]);

        app(CancelOrder::class)($order, 'actor:admin-1', 'admin', 'pemesan membatalkan');

        $head = PlotReservation::query()
            ->where('plot_id', $plot->getKey())
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();

        $this->assertSame(PlotReservationState::RELEASED, $head->state, 'the chain still closes even when the plot diverged');
        $this->assertSame(PlotState::OCCUPIED, $plot->fresh()->plot_state, 'the override must survive the release');
    }

    public function test_a_completed_order_does_not_release_its_plot_reservation(): void
    {
        $plot = $this->makePlot();
        $order = $this->makeOrder(OrderStatus::DIPROSES);
        $this->reserveForOrder($plot, $order);

        app(RecordOrderStatusChange::class)($order, OrderStatus::SELESAI, 'actor:admin-1', 'admin');

        $head = PlotReservation::query()
            ->where('plot_id', $plot->getKey())
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();

        $this->assertSame(PlotReservationState::HELD, $head->state, 'a completed order keeps its plot claim');
        $this->assertSame(PlotState::RESERVED, $plot->fresh()->plot_state);
    }

    public function test_terminal_transition_with_no_active_reservation_does_not_throw(): void
    {
        $order = $this->makeOrder(OrderStatus::MASUK);

        $event = app(CancelOrder::class)($order, 'actor:admin-1', 'admin', 'tanpa reservasi plot');

        $this->assertSame('DIBATALKAN', $event->to_status);
        $this->assertSame('DIBATALKAN', $order->fresh()->status);
    }
}
