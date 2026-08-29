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
use App\Domain\PlotReservation\PlotReservationState;
use App\Livewire\Public\Booking\BookingWizard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

final class BookingWizardPlotPickerTest extends TestCase
{
    use RefreshDatabase;

    private function makeCemetery(
        string $trackingMode,
        string $publicationStatus = CemeteryPublicationStatus::PUBLISHED,
        string $city = LaunchCityCode::JAKARTA,
    ): Cemetery {
        return Cemetery::query()->create([
            'type' => CemeteryType::TPU,
            'publication_status' => $publicationStatus,
            'name' => 'TPU Uji Coba',
            'slug' => 'tpu-uji-coba-'.Str::lower(Str::random(6)),
            'city' => $city,
            'address' => 'Jl. Contoh No. 1',
            'plot_tracking_mode' => $trackingMode,
        ]);
    }

    private function makePlotIn(Cemetery $cemetery): GravePlot
    {
        $block = CemeteryBlock::query()->create([
            'cemetery_id' => $cemetery->getKey(),
            'code' => 'BLOK-A',
            'name' => 'Blok A',
            'capacity' => 1,
        ]);

        return GravePlot::query()->create([
            'block_id' => $block->getKey(),
            'slot' => '001',
            'plot_state' => PlotState::AVAILABLE,
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

    /**
     * Whole-branch review I3a. `openPickerFor()` is a public Livewire
     * method, so `pickerCemeteryId` is client-chosen input: an anonymous
     * visitor holding an unpublished cemetery's real UUID must still learn
     * nothing about its blocks or plots.
     */
    public function test_the_picker_never_exposes_an_unpublished_cemetery(): void
    {
        $unpublished = $this->makeCemetery(
            PlotTrackingMode::GRANULAR,
            publicationStatus: CemeteryPublicationStatus::DRAFT,
        );
        $this->makePlotIn($unpublished);
        $draftId = $this->draftIdAtStep2();

        $component = Livewire::test(BookingWizard::class, ['draftId' => $draftId])
            ->call('openPickerFor', $unpublished->id);

        $this->assertFalse($component->instance()->pickerAppliesTo($unpublished->id));
        $this->assertCount(0, $component->instance()->pickerBlocks());
        $component->assertDontSee('BLOK-A')->assertDontSee('001');
    }

    /**
     * Whole-branch review I3b. The hold is taken BEFORE `saveStep2()`, so a
     * save that then fails validation would otherwise leave a real plot
     * squatted in `reserved` for the whole TTL on behalf of a step that
     * never happened. A cemetery outside the draft's own city is the
     * cheapest genuine `SaveBookingDraftStep` rejection.
     */
    public function test_a_hold_is_released_when_step_2_fails_validation(): void
    {
        $outOfCity = $this->makeCemetery(PlotTrackingMode::GRANULAR, city: LaunchCityCode::BOGOR);
        $plot = $this->makePlotIn($outOfCity);
        $draftId = $this->draftIdAtStep2();

        Livewire::test(BookingWizard::class, ['draftId' => $draftId])
            ->call('openPickerFor', $outOfCity->id)
            ->call('holdPlotForStep2', $outOfCity->id, null, $plot->id)
            ->assertHasErrors('cemetery_id');

        $draft = BookingDraft::query()->findOrFail($draftId);

        // The step really did not advance, and the plot is bookable again.
        $this->assertNull($draft->cemetery_id);
        $this->assertNull(PlotReservation::activeForDraft($draft));
        $this->assertSame(PlotState::AVAILABLE, $plot->fresh()->plot_state);
        $this->assertDatabaseHas('plot_reservations', [
            'plot_id' => $plot->getKey(),
            'state' => PlotReservationState::RELEASED,
        ]);
    }

    /**
     * The counterpart to the test above: a hold that PREDATES this call
     * (returned as the incumbent, not created here) must survive a
     * validation failure — only a hold this call created is rolled back.
     */
    public function test_a_pre_existing_hold_survives_a_later_failed_step_2(): void
    {
        // Same out-of-city rejection as the test above, so the only thing
        // that differs is WHO created the hold.
        $outOfCity = $this->makeCemetery(PlotTrackingMode::GRANULAR, city: LaunchCityCode::BOGOR);
        $plot = $this->makePlotIn($outOfCity);
        $draftId = $this->draftIdAtStep2();
        $draft = BookingDraft::query()->findOrFail($draftId);

        // Established BEFORE this request, so `HoldPlotForDraft` returns it
        // as the incumbent (same plot) rather than creating one.
        $existing = (new HoldPlotForDraft)($plot, $draft, "booking_draft:{$draft->getKey()}");

        Livewire::test(BookingWizard::class, ['draftId' => $draftId])
            ->call('holdPlotForStep2', $outOfCity->id, null, $plot->id)
            // Without this the test would pass vacuously on a save that
            // simply succeeded; the point is that it FAILED and the
            // pre-existing hold survived anyway.
            ->assertHasErrors('cemetery_id')
            ->assertSet('autosaveState', 'failed');

        $still = PlotReservation::activeForDraft($draft->fresh());
        $this->assertNotNull($still);
        $this->assertSame($existing->getKey(), $still->getKey());
        $this->assertSame(PlotState::RESERVED, $plot->fresh()->plot_state);
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
