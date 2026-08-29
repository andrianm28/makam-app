<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\PlotReservation;

use App\Domain\Booking\Models\BookingDraft;
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
use App\Domain\PlotReservation\Actions\ConvertDraftHoldToOrderReservation;
use App\Domain\PlotReservation\Actions\HoldPlotForDraft;
use App\Domain\PlotReservation\Exceptions\DraftPlotHoldNoLongerValidException;
use App\Domain\PlotReservation\Models\PlotReservation;
use App\Domain\PlotReservation\PlotReservationState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ConvertDraftHoldToOrderReservationTest extends TestCase
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

    private function makeOrder(): Order
    {
        return Order::query()->create([
            'reference' => 'MK-2026-'.Str::upper(Str::random(8)),
            'product_type' => ProductType::AT_NEED_SERVICE_ORDER->value,
            'status' => OrderStatus::MASUK->value,
        ]);
    }

    public function test_converted_is_a_known_state(): void
    {
        $this->assertTrue(PlotReservationState::isKnown(PlotReservationState::CONVERTED));
        $this->assertSame('converted', PlotReservationState::CONVERTED);
    }

    public function test_it_appends_a_new_order_anchored_row_and_closes_the_draft_hold(): void
    {
        $plot = $this->makePlot();
        $draft = BookingDraft::query()->create(['current_step' => 2]);
        $held = (new HoldPlotForDraft)($plot, $draft, "booking_draft:{$draft->getKey()}");
        $order = $this->makeOrder();

        $new = (new ConvertDraftHoldToOrderReservation)($held, $order);

        $this->assertSame(PlotReservationState::HELD, $new->state);
        $this->assertSame($order->getKey(), $new->order_id);
        $this->assertNull($new->booking_draft_id);

        $closed = PlotReservation::query()->findOrFail($held->getKey());
        $this->assertSame(PlotReservationState::HELD, $closed->state, 'the original row is append-only and unchanged');

        $latestForPlot = PlotReservation::query()
            ->where('plot_id', $plot->getKey())
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();
        $this->assertCount(3, $latestForPlot, 'the draft hold row, its converted-closing row, and the new order row');

        $states = $latestForPlot->pluck('state')->all();
        $this->assertContains(PlotReservationState::CONVERTED, $states);
        $this->assertSame(PlotState::RESERVED, $plot->fresh()->plot_state);
    }

    public function test_it_throws_when_the_hold_has_expired(): void
    {
        $plot = $this->makePlot();
        $draft = BookingDraft::query()->create(['current_step' => 2]);
        $held = (new HoldPlotForDraft)($plot, $draft, "booking_draft:{$draft->getKey()}", ttlMinutes: -1);
        $order = $this->makeOrder();

        $this->expectException(DraftPlotHoldNoLongerValidException::class);
        (new ConvertDraftHoldToOrderReservation)($held, $order);
    }

    public function test_it_throws_when_the_hold_was_already_converted(): void
    {
        $plot = $this->makePlot();
        $draft = BookingDraft::query()->create(['current_step' => 2]);
        $held = (new HoldPlotForDraft)($plot, $draft, "booking_draft:{$draft->getKey()}");
        $order = $this->makeOrder();

        (new ConvertDraftHoldToOrderReservation)($held, $order);

        $this->expectException(DraftPlotHoldNoLongerValidException::class);
        (new ConvertDraftHoldToOrderReservation)($held, $this->makeOrder());
    }
}
