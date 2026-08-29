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
use App\Domain\PlotReservation\Actions\HoldPlotForDraft;
use App\Domain\PlotReservation\Exceptions\PlotNotAvailableException;
use App\Domain\PlotReservation\Models\PlotReservation;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The draft-hold mirror of `ReservePlotTwoConnectionTest` (read that
 * class's doc block first — same reasoning applies verbatim, substituting
 * `BookingDraft` for `Order`). Outside `RefreshDatabase`'s outer
 * transaction so the first session's commit is genuinely visible to the
 * second; the trailing `migrate:fresh` after EACH test is load-bearing for
 * the same reason.
 *
 * Two distinct races, both closed by `HoldPlotForDraft`'s draft-row lock
 * (step 2a, mirroring `ReservePlot`'s finding I1):
 * `test_a_second_hold_is_refused_after_the_first_commits` proves the
 * PLOT-row lock refuses a second draft claiming an already-held plot;
 * `test_the_same_draft_cannot_hold_two_different_plots_concurrently`
 * proves the DRAFT-row lock refuses the same draft claiming two DIFFERENT
 * plots at once — the two locks guard two different invariants and each
 * needs its own race test, the same way `ReservePlot`'s own class doc
 * block explains its plot-level test cannot also prove the order-level
 * guarantee.
 */
final class HoldPlotForDraftTwoConnectionTest extends TestCase
{
    public function test_a_second_hold_is_refused_after_the_first_commits(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Sequential cross-connection re-read is only meaningful on PostgreSQL');
        }

        $cemetery = Cemetery::query()->create([
            'type' => CemeteryType::TPU,
            'publication_status' => CemeteryPublicationStatus::DRAFT,
            'name' => 'TPU Uji Coba',
            'slug' => 'tpu-uji-coba-'.Str::lower(Str::random(6)),
            'city' => LaunchCityCode::JAKARTA,
            'address' => 'Jl. Contoh No. 1',
        ]);
        $block = CemeteryBlock::query()->create(['cemetery_id' => $cemetery->getKey(), 'code' => 'BLOK-A', 'name' => 'Blok A', 'capacity' => 1]);
        $plot = GravePlot::query()->create(['block_id' => $block->getKey(), 'slot' => '001', 'plot_state' => 'available']);
        $draft = BookingDraft::query()->create(['current_step' => 2]);
        $secondDraft = BookingDraft::query()->create(['current_step' => 2]);

        config(['database.connections.pgsql_race' => config('database.connections.pgsql')]);
        $originalDefault = config('database.default');
        $outcomes = [];
        $drafts = [$draft, $secondDraft];
        try {
            foreach (['pgsql', 'pgsql_race'] as $i => $connectionName) {
                DB::setDefaultConnection($connectionName);
                try {
                    app(HoldPlotForDraft::class)(
                        GravePlot::query()->findOrFail($plot->getKey()),
                        BookingDraft::query()->findOrFail($drafts[$i]->getKey()),
                        "booking_draft:{$drafts[$i]->getKey()}",
                    );
                    $outcomes[] = 'ok';
                } catch (PlotNotAvailableException) {
                    $outcomes[] = 'blocked';
                }
            }
        } finally {
            DB::setDefaultConnection($originalDefault);
            DB::purge('pgsql_race');
        }

        $this->assertSame(['ok', 'blocked'], $outcomes);
        $this->assertSame(1, PlotReservation::query()->count());

        Artisan::call('migrate:fresh');
    }

    /**
     * The draft-row-lock race (step 2a): the SAME draft racing itself
     * against two DIFFERENT plots. Unlike the plot-collision test above,
     * neither call can throw `PlotNotAvailableException` — each locks a
     * DIFFERENT plot row, so plot-level availability is never contended.
     * The draft-row lock is what must refuse the loser: the second
     * session's incumbent re-check, run under the now-locked draft row,
     * must find the first session's already-committed hold and return
     * THAT hold rather than creating a second one. Proof is therefore
     * "exactly one reservation row exists, and both calls return the same
     * id" — not a caught exception.
     */
    public function test_the_same_draft_cannot_hold_two_different_plots_concurrently(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Sequential cross-connection re-read is only meaningful on PostgreSQL');
        }

        $cemetery = Cemetery::query()->create([
            'type' => CemeteryType::TPU,
            'publication_status' => CemeteryPublicationStatus::DRAFT,
            'name' => 'TPU Uji Coba',
            'slug' => 'tpu-uji-coba-'.Str::lower(Str::random(6)),
            'city' => LaunchCityCode::JAKARTA,
            'address' => 'Jl. Contoh No. 1',
        ]);
        $block = CemeteryBlock::query()->create(['cemetery_id' => $cemetery->getKey(), 'code' => 'BLOK-A', 'name' => 'Blok A', 'capacity' => 2]);
        $firstPlot = GravePlot::query()->create(['block_id' => $block->getKey(), 'slot' => '001', 'plot_state' => 'available']);
        $secondPlot = GravePlot::query()->create(['block_id' => $block->getKey(), 'slot' => '002', 'plot_state' => 'available']);
        $draft = BookingDraft::query()->create(['current_step' => 2]);

        config(['database.connections.pgsql_race' => config('database.connections.pgsql')]);
        $originalDefault = config('database.default');
        $reservationIds = [];
        $plots = [$firstPlot, $secondPlot];
        try {
            foreach (['pgsql', 'pgsql_race'] as $i => $connectionName) {
                DB::setDefaultConnection($connectionName);
                $reservation = app(HoldPlotForDraft::class)(
                    GravePlot::query()->findOrFail($plots[$i]->getKey()),
                    BookingDraft::query()->findOrFail($draft->getKey()),
                    "booking_draft:{$draft->getKey()}",
                );
                $reservationIds[] = (string) $reservation->getKey();
            }
        } finally {
            DB::setDefaultConnection($originalDefault);
            DB::purge('pgsql_race');
        }

        $this->assertSame($reservationIds[0], $reservationIds[1], 'the second session must return the incumbent, not create a second hold');
        $this->assertSame(1, PlotReservation::query()->count());

        Artisan::call('migrate:fresh');
    }
}
