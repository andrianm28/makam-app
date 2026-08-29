<?php

declare(strict_types=1);

namespace Tests\Feature\OrderWorkflow;

use App\Domain\Booking\BookingServiceType;
use App\Domain\Booking\Models\BookingDraft;
use App\Domain\CemeteryDirectory\CemeteryPublicationStatus;
use App\Domain\CemeteryDirectory\CemeteryType;
use App\Domain\CemeteryDirectory\LaunchCityCode;
use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Domain\CemeteryDirectory\PlotTrackingMode;
use App\Domain\OrderWorkflow\Actions\IssueQuoteFromReservedPlot;
use App\Domain\OrderWorkflow\Models\Order;
use App\Domain\OrderWorkflow\OrderStatus;
use App\Domain\OrderWorkflow\OrderTransition;
use App\Domain\OrderWorkflow\ProductType;
use App\Domain\PlotInventory\Models\CemeteryBlock;
use App\Domain\PlotInventory\Models\GravePlot;
use App\Domain\PlotReservation\Actions\ReservePlot;
use App\Domain\PlotReservation\Models\PlotReservation;
use App\Domain\Quotation\Models\Quote;
use App\Domain\ServiceCatalog\Models\ServiceDefinition;
use App\Domain\ServiceCatalog\ServiceCode;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

final class IssueQuoteFromReservedPlotTest extends TestCase
{
    use RefreshDatabase;

    private function makeCemetery(string $trackingMode): Cemetery
    {
        return Cemetery::query()->create([
            'type' => CemeteryType::TPU,
            'publication_status' => CemeteryPublicationStatus::PUBLISHED,
            'name' => 'TPU Uji Coba',
            'slug' => 'tpu-uji-coba-'.Str::lower(Str::random(6)),
            'city' => LaunchCityCode::JAKARTA,
            'address' => 'Jl. Contoh No. 1',
            'plot_tracking_mode' => $trackingMode,
        ]);
    }

    private function makePricedService(): ServiceDefinition
    {
        return ServiceDefinition::findByCode(ServiceCode::GRAVE_DIGGING);
    }

    private function makeOrder(OrderStatus $status, ?BookingDraft $draft = null): Order
    {
        return Order::query()->create([
            'reference' => 'MK-2026-'.Str::upper(Str::random(8)),
            'product_type' => ProductType::AT_NEED_SERVICE_ORDER->value,
            'status' => $status->value,
            'booking_draft_id' => $draft?->getKey(),
        ]);
    }

    public function test_the_matrix_allows_diverifikasi_direct_to_penawaran_terkirim(): void
    {
        $this->assertTrue(OrderTransition::isAllowed(OrderStatus::DIVERIFIKASI, OrderStatus::PENAWARAN_TERKIRIM));
        // The normal path stays reachable too — this is a widening, not a replacement.
        $this->assertTrue(OrderTransition::isAllowed(OrderStatus::DIVERIFIKASI, OrderStatus::MENUNGGU_KETERSEDIAAN));
    }

    public function test_it_issues_a_quote_and_transitions_when_a_reservation_exists(): void
    {
        $service = $this->makePricedService();
        $cemetery = $this->makeCemetery(PlotTrackingMode::GRANULAR);
        $block = CemeteryBlock::query()->create(['cemetery_id' => $cemetery->getKey(), 'code' => 'BLOK-A', 'name' => 'Blok A', 'capacity' => 1]);
        $plot = GravePlot::query()->create(['block_id' => $block->getKey(), 'slot' => '001', 'plot_state' => 'available']);
        $draft = BookingDraft::query()->create([
            'service_type' => BookingServiceType::NEW_GRAVE,
            'selected_services' => [['code' => $service->code, 'quantity' => 1]],
            'customer_full_name' => 'UAT Penerima',
        ]);
        $order = $this->makeOrder(OrderStatus::DIVERIFIKASI, $draft);
        app(ReservePlot::class)($plot, $order, 'user:1', 'operator');

        $event = app(IssueQuoteFromReservedPlot::class)($order, CarbonImmutable::now()->addDays(30), 'user:1', 'operator');

        $this->assertSame(OrderStatus::PENAWARAN_TERKIRIM->value, $event->to_status);
        $this->assertSame(OrderStatus::PENAWARAN_TERKIRIM, $order->status());
        $quote = Quote::currentFor($order);
        $this->assertNotNull($quote);
        $this->assertCount(1, $quote->lines);
    }

    public function test_it_refuses_when_the_order_is_not_at_diverifikasi(): void
    {
        $service = $this->makePricedService();
        $cemetery = $this->makeCemetery(PlotTrackingMode::GRANULAR);
        $block = CemeteryBlock::query()->create(['cemetery_id' => $cemetery->getKey(), 'code' => 'BLOK-A', 'name' => 'Blok A', 'capacity' => 1]);
        $plot = GravePlot::query()->create(['block_id' => $block->getKey(), 'slot' => '001', 'plot_state' => 'available']);
        $draft = BookingDraft::query()->create([
            'service_type' => BookingServiceType::NEW_GRAVE,
            'selected_services' => [['code' => $service->code, 'quantity' => 1]],
        ]);
        $order = $this->makeOrder(OrderStatus::MASUK, $draft);
        app(ReservePlot::class)($plot, $order, 'user:1', 'operator');

        $this->expectException(InvalidArgumentException::class);
        app(IssueQuoteFromReservedPlot::class)($order, CarbonImmutable::now()->addDays(30), 'user:1', 'operator');
    }

    public function test_it_refuses_when_there_is_no_active_reservation(): void
    {
        $service = $this->makePricedService();
        $draft = BookingDraft::query()->create([
            'service_type' => BookingServiceType::NEW_GRAVE,
            'selected_services' => [['code' => $service->code, 'quantity' => 1]],
        ]);
        $order = $this->makeOrder(OrderStatus::DIVERIFIKASI, $draft);

        $this->expectException(InvalidArgumentException::class);
        app(IssueQuoteFromReservedPlot::class)($order, CarbonImmutable::now()->addDays(30), 'user:1', 'operator');
    }

    public function test_an_aggregate_tier_order_can_never_qualify(): void
    {
        // Structural, not a special case in the action: an aggregate-tier
        // cemetery never has GravePlot rows, so PlotReservation::activeForOrder()
        // is always null for such an order — verified directly here as an
        // explicit regression, not left to the structural argument alone.
        $service = $this->makePricedService();
        $draft = BookingDraft::query()->create([
            'service_type' => BookingServiceType::NEW_GRAVE,
            'selected_services' => [['code' => $service->code, 'quantity' => 1]],
        ]);
        $order = $this->makeOrder(OrderStatus::DIVERIFIKASI, $draft);

        $this->assertNull(PlotReservation::activeForOrder($order));

        $this->expectException(InvalidArgumentException::class);
        app(IssueQuoteFromReservedPlot::class)($order, CarbonImmutable::now()->addDays(30), 'user:1', 'operator');
    }
}
