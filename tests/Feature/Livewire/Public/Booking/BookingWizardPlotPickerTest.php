<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Public\Booking;

use App\Domain\Booking\Models\BookingDraft;
use App\Domain\CemeteryDirectory\CemeteryPublicationStatus;
use App\Domain\CemeteryDirectory\CemeteryType;
use App\Domain\CemeteryDirectory\LaunchCityCode;
use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Domain\CemeteryDirectory\PlotTrackingMode;
use App\Domain\PlotInventory\Models\CemeteryBlock;
use App\Domain\PlotInventory\Models\GravePlot;
use App\Domain\PlotInventory\PlotState;
use App\Domain\PlotReservation\Actions\HoldPlotForDraft;
use App\Domain\PlotReservation\Models\PlotReservation;
use App\Livewire\Public\Booking\BookingWizard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

final class BookingWizardPlotPickerTest extends TestCase
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

    /**
     * Drives the real component through Step 1 so the returned draft id
     * has a genuine session binding — see this step's own note above.
     */
    private function draftIdAtStep2(): string
    {
        $draftId = Livewire::test(BookingWizard::class)
            ->call('saveStep1', LaunchCityCode::JAKARTA)
            ->get('draftId');

        $this->assertIsString($draftId);

        return $draftId;
    }

    public function test_the_picker_renders_for_a_granular_cemetery_at_step_2(): void
    {
        $cemetery = $this->makeCemetery(PlotTrackingMode::GRANULAR);
        $block = CemeteryBlock::query()->create(['cemetery_id' => $cemetery->getKey(), 'code' => 'BLOK-A', 'name' => 'Blok A', 'capacity' => 1]);
        GravePlot::query()->create(['block_id' => $block->getKey(), 'slot' => '001', 'plot_state' => 'available']);
        $draftId = $this->draftIdAtStep2();

        Livewire::test(BookingWizard::class, ['draftId' => $draftId])
            ->call('openPickerFor', $cemetery->id)
            ->assertSee('BLOK-A')
            ->assertSee('001');
    }

    public function test_the_picker_never_renders_for_an_aggregate_cemetery(): void
    {
        $this->makeCemetery(PlotTrackingMode::AGGREGATE);
        $draftId = $this->draftIdAtStep2();

        Livewire::test(BookingWizard::class, ['draftId' => $draftId])
            ->assertDontSee('Lihat Peta Plot');
    }

    public function test_holding_a_plot_persists_the_hold_and_advances_the_draft(): void
    {
        $cemetery = $this->makeCemetery(PlotTrackingMode::GRANULAR);
        $block = CemeteryBlock::query()->create(['cemetery_id' => $cemetery->getKey(), 'code' => 'BLOK-A', 'name' => 'Blok A', 'capacity' => 1]);
        $plot = GravePlot::query()->create(['block_id' => $block->getKey(), 'slot' => '001', 'plot_state' => 'available']);
        $draftId = $this->draftIdAtStep2();

        Livewire::test(BookingWizard::class, ['draftId' => $draftId])
            ->call('openPickerFor', $cemetery->id)
            ->call('holdPlotForStep2', $cemetery->id, null, $plot->id);

        $draft = BookingDraft::query()->findOrFail($draftId);
        $hold = PlotReservation::activeForDraft($draft);
        $this->assertNotNull($hold);
        $this->assertSame($plot->getKey(), $hold->plot_id);
        $this->assertSame(PlotState::RESERVED, $plot->fresh()->plot_state);
        $this->assertSame(3, $draft->current_step);
    }

    public function test_resuming_the_wizard_shows_the_already_held_plot(): void
    {
        $cemetery = $this->makeCemetery(PlotTrackingMode::GRANULAR);
        $block = CemeteryBlock::query()->create(['cemetery_id' => $cemetery->getKey(), 'code' => 'BLOK-A', 'name' => 'Blok A', 'capacity' => 1]);
        $plot = GravePlot::query()->create(['block_id' => $block->getKey(), 'slot' => '001', 'plot_state' => 'available']);
        $draftId = $this->draftIdAtStep2();
        $draft = BookingDraft::query()->findOrFail($draftId);
        // The draft must actually have saved this cemetery for mount()'s
        // resume branch to know which cemetery's picker to reopen —
        // mirrors what saveStep2() would have set on the real path.
        $draft->forceFill(['cemetery_id' => $cemetery->id])->saveQuietly();
        app(HoldPlotForDraft::class)($plot, $draft, "booking_draft:{$draft->getKey()}");

        // A FRESH mount — no explicit openPickerFor() call — must reopen
        // the picker and show the live hold on its own.
        Livewire::test(BookingWizard::class, ['draftId' => $draftId])
            ->assertSee('Ditahan');
    }
}
