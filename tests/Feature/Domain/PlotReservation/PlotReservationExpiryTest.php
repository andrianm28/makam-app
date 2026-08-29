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
use App\Domain\PlotReservation\Actions\ExpirePlotReservation;
use App\Domain\PlotReservation\Actions\HoldPlotForDraft;
use App\Domain\PlotReservation\Models\PlotReservation;
use App\Domain\PlotReservation\PlotReservationExpiryScheduler;
use App\Domain\PlotReservation\PlotReservationState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class PlotReservationExpiryTest extends TestCase
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

    public function test_it_expires_only_the_stale_held_draft_row(): void
    {
        $expiredPlot = $this->makePlot();
        $expiredDraft = BookingDraft::query()->create(['current_step' => 2]);
        $expiredHold = (new HoldPlotForDraft)($expiredPlot, $expiredDraft, "booking_draft:{$expiredDraft->getKey()}", ttlMinutes: -5);

        $freshPlot = $this->makePlot();
        $freshDraft = BookingDraft::query()->create(['current_step' => 2]);
        $freshHold = (new HoldPlotForDraft)($freshPlot, $freshDraft, "booking_draft:{$freshDraft->getKey()}", ttlMinutes: 15);

        $expired = app(PlotReservationExpiryScheduler::class)->expireStaleDraftHolds();

        $this->assertCount(1, $expired);
        // `plot_reservations` is append-only (`ExpirePlotReservation`, reused
        // unchanged, always INSERTs a new `expired`-state row rather than
        // mutating `$expiredHold`'s own row), so the expired chain's NEW row
        // never shares `$expiredHold`'s id — identify it by plot instead.
        $this->assertSame($expiredPlot->getKey(), $expired->first()->plot_id);
        $this->assertSame(PlotReservationState::EXPIRED, $expired->first()->state);
        $this->assertNotSame($expiredHold->getKey(), $expired->first()->getKey());

        $this->assertSame(PlotState::AVAILABLE, $expiredPlot->fresh()->plot_state);
        $this->assertSame(PlotState::RESERVED, $freshPlot->fresh()->plot_state);

        $freshStillHead = PlotReservation::query()
            ->where('plot_id', $freshPlot->getKey())
            ->orderByDesc('created_at')->orderByDesc('id')->first();
        $this->assertSame(PlotReservationState::HELD, $freshStillHead->state);
    }

    public function test_it_is_idempotent_and_isolates_a_row_already_moved_on(): void
    {
        $plotA = $this->makePlot();
        $draftA = BookingDraft::query()->create(['current_step' => 2]);
        $holdA = (new HoldPlotForDraft)($plotA, $draftA, "booking_draft:{$draftA->getKey()}", ttlMinutes: -5);

        $plotB = $this->makePlot();
        $draftB = BookingDraft::query()->create(['current_step' => 2]);
        $holdB = (new HoldPlotForDraft)($plotB, $draftB, "booking_draft:{$draftB->getKey()}", ttlMinutes: -5);

        // Simulate holdA having already moved on (e.g. converted) between
        // the query and the write, by expiring it directly first.
        app(ExpirePlotReservation::class)($holdA, 'system', 'system');

        $expired = app(PlotReservationExpiryScheduler::class)->expireStaleDraftHolds();

        // Only holdB is genuinely expirable by this run; holdA's row is no
        // longer the live HELD head, and the scheduler must isolate that
        // per-row rather than aborting the whole sweep.
        $this->assertCount(1, $expired);
        // See the plot_id-vs-getKey() note in the first test above.
        $this->assertSame($plotB->getKey(), $expired->first()->plot_id);
    }

    /**
     * Whole-branch review I4. The candidate query is bounded below, so a
     * row that expired long ago is not re-selected on every one-minute
     * run for the life of the table. Only reachable after a sweep outage
     * longer than the window — see the scheduler's class doc block for the
     * operator-recovery cost this deliberately accepts.
     */
    public function test_a_hold_older_than_the_candidate_window_is_not_re_selected(): void
    {
        $ancientPlot = $this->makePlot();
        $ancientDraft = BookingDraft::query()->create(['current_step' => 2]);
        (new HoldPlotForDraft)($ancientPlot, $ancientDraft, "booking_draft:{$ancientDraft->getKey()}", ttlMinutes: -2 * 24 * 60);

        $recentPlot = $this->makePlot();
        $recentDraft = BookingDraft::query()->create(['current_step' => 2]);
        (new HoldPlotForDraft)($recentPlot, $recentDraft, "booking_draft:{$recentDraft->getKey()}", ttlMinutes: -5);

        $expired = app(PlotReservationExpiryScheduler::class)->expireStaleDraftHolds();

        $this->assertCount(1, $expired);
        $this->assertSame($recentPlot->getKey(), $expired->first()->plot_id);
        $this->assertSame(PlotState::RESERVED, $ancientPlot->fresh()->plot_state);
    }

    public function test_a_non_expired_hold_is_left_untouched(): void
    {
        $plot = $this->makePlot();
        $draft = BookingDraft::query()->create(['current_step' => 2]);
        (new HoldPlotForDraft)($plot, $draft, "booking_draft:{$draft->getKey()}", ttlMinutes: 15);

        $expired = app(PlotReservationExpiryScheduler::class)->expireStaleDraftHolds();

        $this->assertCount(0, $expired);
    }
}
