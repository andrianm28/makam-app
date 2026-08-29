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
use App\Domain\PlotReservation\Actions\ConfirmPlotReservation;
use App\Domain\PlotReservation\Actions\ExpirePlotReservation;
use App\Domain\PlotReservation\Actions\ReleasePlotReservation;
use App\Domain\PlotReservation\Models\PlotReservation;
use App\Domain\PlotReservation\PlotReservationState;
use App\Platform\Audit\AuditSource;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class PlotReservationBookingDraftHoldTest extends TestCase
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

    public function test_a_held_row_can_carry_a_booking_draft_id_with_no_order_id(): void
    {
        $plot = $this->makePlot();
        $draft = BookingDraft::query()->create(['current_step' => 2]);

        $row = PlotReservation::query()->create([
            'plot_id' => $plot->getKey(),
            'booking_draft_id' => $draft->getKey(),
            'order_id' => null,
            'state' => PlotReservationState::HELD,
            'reserved_by_ref' => "booking_draft:{$draft->getKey()}",
            'reserved_at' => now(),
            'expires_at' => now()->addMinutes(15),
        ]);

        $this->assertSame($draft->getKey(), $row->booking_draft_id);
        $this->assertNull($row->order_id);
        $this->assertInstanceOf(CarbonImmutable::class, $row->expires_at);
    }

    public function test_active_for_draft_returns_the_head_row_when_held(): void
    {
        $plot = $this->makePlot();
        $draft = BookingDraft::query()->create(['current_step' => 2]);

        $this->assertNull(PlotReservation::activeForDraft($draft));

        $row = PlotReservation::query()->create([
            'plot_id' => $plot->getKey(),
            'booking_draft_id' => $draft->getKey(),
            'state' => PlotReservationState::HELD,
            'reserved_by_ref' => "booking_draft:{$draft->getKey()}",
            'reserved_at' => now(),
            'expires_at' => now()->addMinutes(15),
        ]);

        $incumbent = PlotReservation::activeForDraft($draft);
        $this->assertNotNull($incumbent);
        $this->assertSame($row->getKey(), $incumbent->getKey());
    }

    public function test_active_for_draft_is_null_once_the_chain_is_released(): void
    {
        $plot = $this->makePlot();
        $draft = BookingDraft::query()->create(['current_step' => 2]);

        PlotReservation::query()->create([
            'plot_id' => $plot->getKey(),
            'booking_draft_id' => $draft->getKey(),
            'state' => PlotReservationState::HELD,
            'reserved_by_ref' => "booking_draft:{$draft->getKey()}",
            'reserved_at' => now(),
            'expires_at' => now()->addMinutes(15),
        ]);
        $held = PlotReservation::activeForDraft($draft);

        (new ReleasePlotReservation)($held, 'system', 'system', auditSource: AuditSource::Job);

        $this->assertNull(PlotReservation::activeForDraft($draft));
    }

    public function test_expire_confirm_and_release_all_carry_booking_draft_id_forward(): void
    {
        foreach ([
            'expire' => fn (PlotReservation $held) => (new ExpirePlotReservation)($held, 'system', 'system', auditSource: AuditSource::Job),
            'confirm' => fn (PlotReservation $held) => (new ConfirmPlotReservation)($held, 'system', 'system', auditSource: AuditSource::Job),
            'release' => fn (PlotReservation $held) => (new ReleasePlotReservation)($held, 'system', 'system', auditSource: AuditSource::Job),
        ] as $label => $transition) {
            $plot = $this->makePlot();
            $draft = BookingDraft::query()->create(['current_step' => 2]);

            $held = PlotReservation::query()->create([
                'plot_id' => $plot->getKey(),
                'booking_draft_id' => $draft->getKey(),
                'state' => PlotReservationState::HELD,
                'reserved_by_ref' => "booking_draft:{$draft->getKey()}",
                'reserved_at' => now(),
                'expires_at' => now()->addMinutes(15),
            ]);

            $result = $transition($held);

            $this->assertSame($draft->getKey(), $result->booking_draft_id, "booking_draft_id was dropped by {$label}");
            $this->assertNull($result->order_id, "order_id should stay null for a draft-only chain after {$label}");
        }
    }
}
