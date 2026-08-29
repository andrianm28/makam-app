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
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The draft-hold mirror of `ReservePlotTwoConnectionTest` (read that
 * class's doc block first — same reasoning applies verbatim, substituting
 * `BookingDraft` for `Order`). Outside `RefreshDatabase`'s outer
 * transaction so the first session's commit is genuinely visible to the
 * second; the `migrate:fresh` in `tearDown()` is load-bearing for the same
 * reason.
 *
 * `test_a_second_hold_is_refused_after_the_first_commits` proves the
 * PLOT-row lock refuses a second draft claiming an already-held plot —
 * the two sessions here are genuinely sequential-but-independent (two
 * DIFFERENT drafts), so session B's plot-level assert, reached only
 * inside the transaction under the locked plot row, is what refuses it.
 *
 * `test_a_sequential_same_draft_different_plot_call_releases_the_incumbent_and_holds_the_new_plot`
 * proves the whole-branch review's C2 contract across a real connection
 * boundary: session B observes session A's committed hold on a DIFFERENT
 * plot, releases it, and claims the plot it was actually asked for. It
 * previously asserted the opposite (session B returns the incumbent
 * unchanged) — that was the behaviour, and it was the defect.
 *
 * NOT VERIFIED ON THIS HOST, stated rather than assumed: neither test
 * exercises the DRAFT-row lock. Because this repo's two-connection
 * pattern is sequential (session A fully commits before session B
 * starts), the two sessions never contend for it — so mutation-testing
 * that lock out still leaves both tests green. Same limitation
 * `ReservePlot`'s own class doc
 * block records for the analogous order-lock race ("only a true parallel
 * race, not sequential sessions, can pass the pre-check and reach the
 * locked re-check") — which is why `ReservePlotTwoConnectionTest` never
 * attempts this shape of test for orders either. The draft-row lock
 * itself (step 2a of `HoldPlotForDraft`) is verified by direct code
 * reading only; exercising it under true concurrency would need forked-
 * process (pcntl) test infrastructure this repo does not have.
 */
final class HoldPlotForDraftTwoConnectionTest extends TestCase
{
    /**
     * In `tearDown()`, not at the end of each test body: these tests commit
     * for real (no `RefreshDatabase` transaction to roll back), so a FAILING
     * assertion used to skip the reset and leave its rows behind for every
     * later test in the run — which is how the C2 fix first presented, as
     * four unrelated `ReservePlotTest` count failures. A failure here must
     * cost one red test, not a poisoned suite.
     */
    protected function tearDown(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            Artisan::call('migrate:fresh');
        }

        parent::tearDown();
    }

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
    }

    /**
     * The SAME draft, called sequentially (not concurrently) against two
     * DIFFERENT plots. Unlike the plot-collision test above, neither call
     * can throw `PlotNotAvailableException` — each locks a DIFFERENT plot
     * row, so plot-level availability is never contended.
     *
     * REWRITTEN by the whole-branch review's C2 fix. This test previously
     * asserted that session B returned session A's incumbent unchanged.
     * That WAS the behaviour, and it was the defect: a customer who walks
     * back into Step 2 and picks a different plot kept the old hold while
     * the wizard moved the draft's saved cemetery on, so the order could
     * ship with a plot the customer never chose — in another cemetery
     * entirely. The contract now is "the most recent choice wins", so what
     * this test pins is the opposite outcome, across a real connection
     * boundary: session A's committed hold is RELEASED and its plot
     * returns to `available`, and session B's newly created hold is the
     * draft's only live one.
     *
     * What this does NOT prove: that the draft-row lock (step 2a inside
     * the transaction) is load-bearing. Because the two sessions here run
     * sequentially — session A fully commits before session B starts —
     * nothing here contends for that lock. Removing the draft-row lock
     * from `HoldPlotForDraft` leaves this test green. See the class doc
     * block for why: this is the same limitation `ReservePlot`'s own doc
     * block records for the analogous order-lock race, and the same reason
     * `ReservePlotTwoConnectionTest` never attempts this shape of test for
     * orders.
     */
    public function test_a_sequential_same_draft_different_plot_call_releases_the_incumbent_and_holds_the_new_plot(): void
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

        $this->assertNotSame($reservationIds[0], $reservationIds[1], 'the second session must create a new hold for the newly chosen plot');

        // held(first) + released(first) + held(second) — append-only.
        $this->assertSame(3, PlotReservation::query()->count());

        $this->assertSame(PlotState::AVAILABLE, $firstPlot->fresh()->plot_state);
        $this->assertSame(PlotState::RESERVED, $secondPlot->fresh()->plot_state);

        $live = PlotReservation::activeForDraft($draft->fresh());
        $this->assertNotNull($live);
        $this->assertSame($reservationIds[1], (string) $live->getKey());
        $this->assertSame($secondPlot->getKey(), $live->plot_id);
    }
}
