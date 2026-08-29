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
 * `test_a_second_hold_is_refused_after_the_first_commits` proves the
 * PLOT-row lock refuses a second draft claiming an already-held plot —
 * the two sessions here are genuinely sequential-but-independent (two
 * DIFFERENT drafts), so session B's plot-level assert, reached only
 * inside the transaction under the locked plot row, is what refuses it.
 *
 * `test_a_sequential_same_draft_different_plot_call_returns_the_incumbent_not_a_second_hold`
 * proves only the OUTER idempotency pre-check
 * (`PlotReservation::activeForDraft()`, run before `DB::transaction`
 * even opens): session B's pre-check already observes session A's
 * committed row and returns the incumbent immediately. NOT VERIFIED ON
 * THIS HOST, stated rather than assumed: because this repo's two-
 * connection pattern is sequential (session A fully commits before
 * session B starts), session B's pre-check short-circuits before the
 * draft-row lock is ever reached — so this test does NOT prove the
 * draft-row lock itself is load-bearing (mutation-testing it out still
 * leaves this test green). Same limitation `ReservePlot`'s own class doc
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
     * The SAME draft, called sequentially (not concurrently) against two
     * DIFFERENT plots. Unlike the plot-collision test above, neither call
     * can throw `PlotNotAvailableException` — each locks a DIFFERENT plot
     * row, so plot-level availability is never contended.
     *
     * What this proves: session B's OUTER idempotency pre-check
     * (`PlotReservation::activeForDraft()`, before `DB::transaction` even
     * opens) finds session A's already-committed hold and returns it
     * instead of creating a second one — "exactly one reservation row
     * exists, and both calls return the same id".
     *
     * What this does NOT prove: that the draft-row lock (step 2a inside
     * the transaction) is load-bearing. Because the two sessions here run
     * sequentially — session A fully commits before session B starts —
     * session B's outer pre-check already short-circuits before the
     * transaction, let alone the lock, is ever reached. Removing the
     * draft-row lock from `HoldPlotForDraft` leaves this test green. See
     * the class doc block for why: this is the same limitation
     * `ReservePlot`'s own doc block records for the analogous order-lock
     * race, and the same reason `ReservePlotTwoConnectionTest` never
     * attempts this shape of test for orders.
     */
    public function test_a_sequential_same_draft_different_plot_call_returns_the_incumbent_not_a_second_hold(): void
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
