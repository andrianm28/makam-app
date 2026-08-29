<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\PlotReservation;

use App\Domain\Booking\Models\BookingDraft;
use App\Domain\CemeteryDirectory\CemeteryPublicationStatus;
use App\Domain\CemeteryDirectory\CemeteryType;
use App\Domain\CemeteryDirectory\LaunchCityCode;
use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Domain\PlotInventory\Models\CemeteryBlock;
use App\Domain\PlotInventory\Models\GravePlot;
use App\Domain\PlotInventory\PlotState;
use App\Domain\PlotReservation\Actions\HoldPlotForDraft;
use App\Domain\PlotReservation\Exceptions\PlotNotAvailableException;
use App\Domain\PlotReservation\Models\PlotReservation;
use App\Domain\PlotReservation\PlotReservationState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class HoldPlotForDraftTest extends TestCase
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

    public function test_it_holds_an_available_plot_for_a_draft(): void
    {
        $plot = $this->makePlot();
        $draft = BookingDraft::query()->create(['current_step' => 2]);

        $row = (new HoldPlotForDraft)($plot, $draft, "booking_draft:{$draft->getKey()}");

        $this->assertSame(PlotReservationState::HELD, $row->state);
        $this->assertSame($draft->getKey(), $row->booking_draft_id);
        $this->assertNull($row->order_id);
        $this->assertNotNull($row->expires_at);
        $this->assertSame(PlotState::RESERVED, $plot->fresh()->plot_state);
    }

    public function test_the_default_ttl_comes_from_config(): void
    {
        config(['plot-reservation.draft_hold_ttl_minutes' => 7]);

        $plot = $this->makePlot();
        $draft = BookingDraft::query()->create(['current_step' => 2]);

        $row = (new HoldPlotForDraft)($plot, $draft, "booking_draft:{$draft->getKey()}");

        $this->assertEqualsWithDelta(
            now()->addMinutes(7)->getTimestamp(),
            $row->expires_at->getTimestamp(),
            2,
        );
    }

    public function test_an_explicit_ttl_overrides_config(): void
    {
        $plot = $this->makePlot();
        $draft = BookingDraft::query()->create(['current_step' => 2]);

        $row = (new HoldPlotForDraft)($plot, $draft, "booking_draft:{$draft->getKey()}", ttlMinutes: 3);

        $this->assertEqualsWithDelta(
            now()->addMinutes(3)->getTimestamp(),
            $row->expires_at->getTimestamp(),
            2,
        );
    }

    public function test_a_duplicate_hold_by_the_same_draft_returns_the_incumbent(): void
    {
        $plot = $this->makePlot();
        $draft = BookingDraft::query()->create(['current_step' => 2]);

        $first = (new HoldPlotForDraft)($plot, $draft, "booking_draft:{$draft->getKey()}");
        $second = (new HoldPlotForDraft)($plot, $draft, "booking_draft:{$draft->getKey()}");

        $this->assertSame($first->getKey(), $second->getKey());
        $this->assertSame(1, PlotReservation::query()->count());
    }

    public function test_it_refuses_a_plot_that_is_not_available(): void
    {
        $plot = $this->makePlot();
        $draft = BookingDraft::query()->create(['current_step' => 2]);
        $otherDraft = BookingDraft::query()->create(['current_step' => 2]);

        (new HoldPlotForDraft)($plot, $draft, "booking_draft:{$draft->getKey()}");

        $this->expectException(PlotNotAvailableException::class);
        (new HoldPlotForDraft)($plot->fresh(), $otherDraft, "booking_draft:{$otherDraft->getKey()}");
    }
}
