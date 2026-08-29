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
use App\Domain\PlotReservation\PlotReservationAuditActions;
use App\Domain\PlotReservation\PlotReservationState;
use App\Platform\Audit\Models\AuditEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class HoldPlotForDraftTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A fresh plot. Passing `$cemetery` puts it in an EXISTING cemetery
     * (two plots the customer could swap between within one TPU); omitting
     * it creates a new cemetery, which is what the cross-cemetery tests
     * want.
     */
    private function makePlot(?Cemetery $cemetery = null, string $slot = '001'): GravePlot
    {
        $cemetery ??= $this->makeCemetery();

        $block = CemeteryBlock::query()->firstOrCreate(
            ['cemetery_id' => $cemetery->getKey(), 'code' => 'BLOK-A'],
            ['name' => 'Blok A', 'capacity' => 10],
        );

        return GravePlot::query()->create(['block_id' => $block->getKey(), 'slot' => $slot, 'plot_state' => 'available']);
    }

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

    /**
     * Whole-branch review C2. A customer who walks back into Step 2 and
     * picks again must end up holding the plot they just chose — the old
     * incumbent is released, not silently kept.
     */
    public function test_holding_a_different_plot_releases_the_previous_hold(): void
    {
        $cemetery = $this->makeCemetery();
        $first = $this->makePlot($cemetery, slot: '001');
        $second = $this->makePlot($cemetery, slot: '002');
        $draft = BookingDraft::query()->create(['current_step' => 2]);

        $firstHold = (new HoldPlotForDraft)($first, $draft, "booking_draft:{$draft->getKey()}");
        $secondHold = (new HoldPlotForDraft)($second, $draft, "booking_draft:{$draft->getKey()}");

        $this->assertNotSame($firstHold->getKey(), $secondHold->getKey());
        $this->assertSame($second->getKey(), $secondHold->plot_id);
        $this->assertSame(PlotReservationState::HELD, $secondHold->state);

        // The abandoned plot is bookable again, and its chain records why.
        $this->assertSame(PlotState::AVAILABLE, $first->fresh()->plot_state);
        $this->assertSame(PlotState::RESERVED, $second->fresh()->plot_state);
        $this->assertDatabaseHas('plot_reservations', [
            'plot_id' => $first->getKey(),
            'state' => PlotReservationState::RELEASED,
        ]);

        // Exactly one live hold for the draft, and it is the new one.
        $active = PlotReservation::activeForDraft($draft->fresh());
        $this->assertNotNull($active);
        $this->assertSame($secondHold->getKey(), $active->getKey());
    }

    /**
     * The same mechanism across a cemetery boundary — the worst form of
     * C2, where the draft's saved `cemetery_id` would otherwise point at
     * one TPU while the reservation sat in another.
     */
    public function test_switching_to_a_plot_in_a_different_cemetery_releases_the_previous_hold(): void
    {
        $plotInA = $this->makePlot();
        $plotInB = $this->makePlot();
        $draft = BookingDraft::query()->create(['current_step' => 2]);

        (new HoldPlotForDraft)($plotInA, $draft, "booking_draft:{$draft->getKey()}");
        $switched = (new HoldPlotForDraft)($plotInB, $draft, "booking_draft:{$draft->getKey()}");

        $this->assertSame($plotInB->getKey(), $switched->plot_id);
        $this->assertSame(PlotState::AVAILABLE, $plotInA->fresh()->plot_state);
        $this->assertSame(PlotState::RESERVED, $plotInB->fresh()->plot_state);
        $this->assertSame($switched->getKey(), PlotReservation::activeForDraft($draft->fresh())?->getKey());
    }

    /**
     * The release is a real, audited domain transition, not a quiet
     * cleanup — the operator trail must be able to explain why a plot the
     * customer was holding became available again.
     */
    public function test_a_plot_switch_is_audited_with_its_reason(): void
    {
        $cemetery = $this->makeCemetery();
        $first = $this->makePlot($cemetery, slot: '001');
        $second = $this->makePlot($cemetery, slot: '002');
        $draft = BookingDraft::query()->create(['current_step' => 2]);

        (new HoldPlotForDraft)($first, $draft, "booking_draft:{$draft->getKey()}");
        (new HoldPlotForDraft)($second, $draft, "booking_draft:{$draft->getKey()}");

        $released = AuditEvent::query()
            ->where('action', PlotReservationAuditActions::PLOT_RESERVATION_RELEASED)
            ->firstOrFail();

        $this->assertSame('customer', $released->actor_role);
        $this->assertSame("booking_draft:{$draft->getKey()}", $released->actor_ref);
        $this->assertStringContainsString('customer selected a different plot', (string) $released->reason);
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
