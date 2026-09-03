<?php

declare(strict_types=1);

namespace Tests\Feature\OrderWorkflow;

use App\Domain\Booking\BookingServiceType;
use App\Domain\Booking\BookingWizardStep;
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
            'current_step' => BookingWizardStep::CONFIRMATION,
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

    /**
     * The customer held a plot in cemetery A, then changed city — which
     * `BookingWizard::selectCity()` deliberately handles by dropping the
     * cemetery and leaving the hold to its own TTL — and finished the wizard
     * on cemetery B without ever opening a picker again. Converting that hold
     * would anchor a plot in A onto an order for B.
     *
     * The failure mode is "do not convert", not a thrown exception: B may be
     * aggregate-tier with no picker at all, so blocking the submission and
     * telling the customer to re-pick would be an unrecoverable loop until
     * the stale hold's TTL expired. The order lands on the ordinary "no plot
     * picked" path instead.
     */
    public function test_a_hold_in_a_different_cemetery_than_the_draft_is_not_converted(): void
    {
        $abandoned = $this->makeCemetery();
        $chosen = $this->makeCemetery();

        $block = CemeteryBlock::query()->create(['cemetery_id' => $abandoned->getKey(), 'code' => 'BLOK-A', 'name' => 'Blok A', 'capacity' => 1]);
        $plot = GravePlot::query()->create(['block_id' => $block->getKey(), 'slot' => '001', 'plot_state' => 'available']);

        // The draft finally saved the OTHER cemetery.
        $draft = $this->makeDraft($chosen);
        $held = (new HoldPlotForDraft)($plot, $draft, "booking_draft:{$draft->getKey()}");

        $order = app(SubmitBookingDraft::class)($draft, 'idem-'.Str::random(8));

        $this->assertSame(
            0,
            PlotReservation::query()->whereNotNull('order_id')->count(),
            'An order for cemetery B must never carry a reservation for a plot in cemetery A.',
        );
        $this->assertNotNull($order->fresh(), 'The submission itself must still succeed.');

        // The abandoned hold is left exactly as it was, to expire on its own
        // TTL — the same disposal `selectCity()` already relies on.
        $this->assertSame(PlotReservationState::HELD, $held->fresh()->state);
        $this->assertSame(
            1,
            PlotReservation::query()->count(),
            'Nothing is written for the mismatched hold — not a conversion, not a release.',
        );
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
