<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\PlotReservation;

use App\Domain\CemeteryDirectory\CemeteryPublicationStatus;
use App\Domain\CemeteryDirectory\CemeteryType;
use App\Domain\CemeteryDirectory\LaunchCityCode;
use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Domain\OrderWorkflow\Models\Order;
use App\Domain\OrderWorkflow\OrderStatus;
use App\Domain\OrderWorkflow\ProductType;
use App\Domain\PlotInventory\Models\CemeteryBlock;
use App\Domain\PlotInventory\Models\GravePlot;
use App\Domain\PlotReservation\Actions\ReservePlot;
use App\Domain\PlotReservation\Exceptions\PlotNotAvailableException;
use App\Domain\PlotReservation\Models\PlotReservation;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The two-connection reservation test, OUTSIDE the `RefreshDatabase`
 * outer transaction — the `RecordOrderStatusChangeTwoConnectionTest`
 * pattern (its class doc block explains why `RefreshDatabase` cannot be
 * used here: the fixture must be COMMITTED before the second logical
 * session queries it).
 *
 * The second session reserves with a DIFFERENT order, deliberately.
 * `ReservePlot`'s first step is order-level idempotency — an order with
 * an active reservation returns the incumbent — so a second attempt with
 * the SAME order would return the incumbent (outcome `ok`) instead of
 * reaching the plot-level backstop. What this test exists to prove is
 * that the second session's `lockForUpdate()` re-read observes the first
 * session's already-committed `plot_state = reserved` and refuses with
 * `PlotNotAvailableException`, leaving exactly one reservation row.
 * A genuine two-writer race — both sessions passing the `available`
 * assert before either commits, with only
 * `plot_reservations_active_hold` between them — is covered separately
 * by `ReservePlotTest::test_duplicate_active_hold_is_classified_as_
 * conflict` (the direct-insert simulation) and is out of scope on the
 * 2/4 host, exactly as `RecordOrderStatusChangeTwoConnectionTest`'s own
 * doc block records for its lane.
 *
 * The trailing `migrate:fresh` is LOAD-BEARING, not a nicety: the two
 * sessions COMMIT real rows, and without the wipe those rows leak into
 * every later `RefreshDatabase` test class in the same process — the
 * `RecordOrderStatusChangeTwoConnectionTest` doc block records that as
 * a verified in-suite failure.
 */
final class ReservePlotTwoConnectionTest extends TestCase
{
    public function test_a_second_reservation_is_refused_after_the_first_commits(): void
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
        $order = Order::query()->create([
            'reference' => 'MK-2026-'.Str::upper(Str::random(8)),
            'product_type' => ProductType::AT_NEED_SERVICE_ORDER->value,
            'status' => OrderStatus::DIVERIFIKASI->value,
        ]);
        $secondOrder = Order::query()->create([
            'reference' => 'MK-2026-'.Str::upper(Str::random(8)),
            'product_type' => ProductType::AT_NEED_SERVICE_ORDER->value,
            'status' => OrderStatus::DIVERIFIKASI->value,
        ]);

        config(['database.connections.pgsql_race' => config('database.connections.pgsql')]);
        $originalDefault = config('database.default');
        $outcomes = [];
        $orders = [$order, $secondOrder];
        try {
            foreach (['pgsql', 'pgsql_race'] as $i => $connectionName) {
                DB::setDefaultConnection($connectionName);
                try {
                    app(ReservePlot::class)(
                        GravePlot::query()->findOrFail($plot->getKey()),
                        Order::query()->findOrFail($orders[$i]->getKey()),
                        'actor:system',
                        'system',
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

        // Wipe the committed rows so later `RefreshDatabase` test classes
        // in this same process start from an empty, migrated schema (they
        // begin a transaction, they do not re-migrate — see the class doc
        // block).
        Artisan::call('migrate:fresh');
    }
}
