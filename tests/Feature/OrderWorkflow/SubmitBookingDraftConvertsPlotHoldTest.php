<?php

declare(strict_types=1);

namespace Tests\Feature\OrderWorkflow;

use App\Domain\Booking\BookingServiceType;
use App\Domain\Booking\Models\BookingDraft;
use App\Domain\CemeteryDirectory\CemeteryPublicationStatus;
use App\Domain\CemeteryDirectory\CemeteryType;
use App\Domain\CemeteryDirectory\LaunchCityCode;
use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Domain\OrderWorkflow\Actions\SubmitBookingDraft;
use App\Domain\OrderWorkflow\Models\Order;
use App\Domain\PlotInventory\Models\CemeteryBlock;
use App\Domain\PlotInventory\Models\GravePlot;
use App\Domain\PlotInventory\PlotState;
use App\Domain\PlotReservation\Actions\HoldPlotForDraft;
use App\Domain\PlotReservation\Exceptions\DraftPlotHoldNoLongerValidException;
use App\Domain\PlotReservation\Models\PlotReservation;
use App\Domain\PlotReservation\PlotReservationState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class SubmitBookingDraftConvertsPlotHoldTest extends TestCase
{
    use RefreshDatabase;

    private function makeCemetery(): Cemetery
    {
        return Cemetery::query()->create([
            'type' => CemeteryType::TPU,
            'publication_status' => CemeteryPublicationStatus::DRAFT,
            'name' => 'TPU Uji Coba',
            'slug' => 'tpu-uji-coba-'.Str::lower(Str::random(6)),
            'city' => LaunchCityCode::JAKARTA,
            'address' => 'Jl. Contoh No. 1',
        ]);
    }

    private function makeDraft(Cemetery $cemetery): BookingDraft
    {
        return BookingDraft::query()->create([
            'current_step' => 8,
            'city_code' => LaunchCityCode::JAKARTA,
            'cemetery_id' => $cemetery->getKey(),
            'service_type' => BookingServiceType::NEW_GRAVE,
            'customer_full_name' => 'Uji Coba',
            'customer_mobile' => '081200000000',
            'customer_relationship' => 'anak',
        ]);
    }

    public function test_a_held_draft_plot_converts_to_an_order_anchored_reservation_on_submit(): void
    {
        $cemetery = $this->makeCemetery();
        $block = CemeteryBlock::query()->create(['cemetery_id' => $cemetery->getKey(), 'code' => 'BLOK-A', 'name' => 'Blok A', 'capacity' => 1]);
        $plot = GravePlot::query()->create(['block_id' => $block->getKey(), 'slot' => '001', 'plot_state' => 'available']);
        $draft = $this->makeDraft($cemetery);
        $held = (new HoldPlotForDraft)($plot, $draft, "booking_draft:{$draft->getKey()}");

        $order = app(SubmitBookingDraft::class)($draft, 'idem-'.Str::random(8));

        $reservation = PlotReservation::query()->where('order_id', $order->getKey())->first();
        $this->assertNotNull($reservation);
        $this->assertSame(PlotReservationState::HELD, $reservation->state);
        $this->assertSame($plot->getKey(), $reservation->plot_id);

        $closedDraftHold = PlotReservation::query()->findOrFail($held->getKey());
        $this->assertSame(PlotReservationState::HELD, $closedDraftHold->state, 'append-only: original row unchanged');

        $this->assertSame(PlotState::RESERVED, $plot->fresh()->plot_state);
    }

    public function test_a_draft_with_no_plot_hold_submits_normally(): void
    {
        $cemetery = $this->makeCemetery();
        $draft = $this->makeDraft($cemetery);

        $order = app(SubmitBookingDraft::class)($draft, 'idem-'.Str::random(8));

        $this->assertNotNull($order);
        $this->assertSame(0, PlotReservation::query()->count());
    }

    public function test_an_expired_hold_blocks_the_whole_submission(): void
    {
        $cemetery = $this->makeCemetery();
        $block = CemeteryBlock::query()->create(['cemetery_id' => $cemetery->getKey(), 'code' => 'BLOK-A', 'name' => 'Blok A', 'capacity' => 1]);
        $plot = GravePlot::query()->create(['block_id' => $block->getKey(), 'slot' => '001', 'plot_state' => 'available']);
        $draft = $this->makeDraft($cemetery);
        (new HoldPlotForDraft)($plot, $draft, "booking_draft:{$draft->getKey()}", ttlMinutes: -1);

        $this->expectException(DraftPlotHoldNoLongerValidException::class);

        try {
            app(SubmitBookingDraft::class)($draft, 'idem-'.Str::random(8));
        } finally {
            $this->assertSame(0, Order::query()->count(), 'the whole transaction must roll back — no orphaned order');
        }
    }
}
