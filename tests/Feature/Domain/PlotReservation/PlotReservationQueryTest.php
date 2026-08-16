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
use App\Domain\PlotInventory\PlotState;
use App\Domain\PlotReservation\Actions\ConfirmPlotReservation;
use App\Domain\PlotReservation\Actions\ExpirePlotReservation;
use App\Domain\PlotReservation\Actions\ReleasePlotReservation;
use App\Domain\PlotReservation\Actions\ReservePlot;
use App\Domain\PlotReservation\Models\PlotReservation;
use App\Domain\PlotReservation\PlotReservationState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Pins the head-row semantics of `PlotReservation::activeForOrder()` /
 * `activeForPlot()` — the chain incumbent resolution the booking
 * integration (Lane 3) and `ReservePlot`'s idempotency pre-check depend
 * on.
 *
 * Every case forces ALL chain rows to the SAME `created_at` second, so
 * the head resolves purely by `id` (UUIDv7, millisecond-monotonic via
 * `HasUuids`) — the only ordering that is portable across PostgreSQL and
 * SQLite. This is the regression net for the fix round that removed the
 * per-state stamp tiebreakers (`confirmed_at`/`released_at`/`expired_at`):
 * PostgreSQL sorts NULLs FIRST under `DESC` and SQLite sorts them LAST,
 * so any such tiebreaker resolves the head differently per engine — an
 * order whose reservation was released/expired would still report an
 * active reservation on PG, and a re-opened chain's fresh `held` head
 * would be hidden behind an older stamped row on SQLite.
 */
final class PlotReservationQueryTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @var string one fixed second for every chain row, chosen outside
     *             any real now()
     */
    private const string PINNED_CREATED_AT = '2026-08-16 08:30:57';

    private function cemetery(): Cemetery
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

    private function plot(string $slot): GravePlot
    {
        $cemetery = $this->cemetery();
        $block = CemeteryBlock::query()->create([
            'cemetery_id' => $cemetery->getKey(),
            'code' => 'BLOK-A',
            'name' => 'Blok A',
            'capacity' => 2,
        ]);

        return GravePlot::query()->create([
            'block_id' => $block->getKey(),
            'slot' => $slot,
            'plot_state' => PlotState::AVAILABLE,
        ]);
    }

    private function order(): Order
    {
        return Order::query()->create([
            'reference' => 'MK-2026-'.Str::upper(Str::random(8)),
            'product_type' => ProductType::AT_NEED_SERVICE_ORDER->value,
            'status' => OrderStatus::DIVERIFIKASI->value,
        ]);
    }

    /**
     * Collapse every chain row onto the same `created_at` second so the
     * head must be resolved by `id` alone.
     */
    private function pinCreatedAt(): void
    {
        DB::table('plot_reservations')->update(['created_at' => self::PINNED_CREATED_AT]);
    }

    public function test_released_head_returns_null(): void
    {
        $plot = $this->plot('001');
        $order = $this->order();

        $held = app(ReservePlot::class)($plot, $order, 'user:1', 'operator');
        app(ReleasePlotReservation::class)($held, 'user:1', 'operator');
        $this->pinCreatedAt();

        $this->assertNull(PlotReservation::activeForOrder($order));
    }

    public function test_expired_head_returns_null(): void
    {
        $plot = $this->plot('001');
        $order = $this->order();

        $held = app(ReservePlot::class)($plot, $order, 'user:1', 'operator');
        app(ExpirePlotReservation::class)($held, 'user:1', 'operator');
        $this->pinCreatedAt();

        $this->assertNull(PlotReservation::activeForOrder($order));
    }

    public function test_confirmed_head_is_returned_by_identity(): void
    {
        $plot = $this->plot('001');
        $order = $this->order();

        $held = app(ReservePlot::class)($plot, $order, 'user:1', 'operator');
        $confirmed = app(ConfirmPlotReservation::class)($held, 'user:1', 'operator');
        $this->pinCreatedAt();

        $head = PlotReservation::activeForOrder($order);
        $this->assertNotNull($head);
        $this->assertSame($confirmed->getKey(), $head->getKey(), 'the head must be the confirmed row, not the held row');
        $this->assertSame(PlotReservationState::CONFIRMED, $head->state);
    }

    public function test_reopened_chain_returns_the_new_held_head_by_identity(): void
    {
        $firstPlot = $this->plot('001');
        $secondPlot = $this->plot('002');
        $order = $this->order();

        $held = app(ReservePlot::class)($firstPlot, $order, 'user:1', 'operator');
        app(ExpirePlotReservation::class)($held, 'user:1', 'operator');
        $this->assertNull(PlotReservation::activeForOrder($order));

        // A fresh hold on a second available plot re-opens the ORDER's
        // chain — `activeForOrder` must resolve the head by identity
        // (the new row), never by state alone (the superseded held row).
        $reheld = app(ReservePlot::class)($secondPlot, $order, 'user:1', 'operator');
        $this->pinCreatedAt();

        $head = PlotReservation::activeForOrder($order);
        $this->assertNotNull($head);
        $this->assertSame($reheld->getKey(), $head->getKey(), 'the head must be the NEW held row, not the superseded one');
        $this->assertSame($secondPlot->getKey(), $head->plot_id);
        $this->assertSame(PlotReservationState::HELD, $head->state);
    }

    public function test_active_for_plot_mirrors_the_head_semantics(): void
    {
        $plot = $this->plot('001');
        $order = $this->order();

        $held = app(ReservePlot::class)($plot, $order, 'user:1', 'operator');
        $this->assertSame($held->getKey(), PlotReservation::activeForPlot($plot)?->getKey());

        app(ExpirePlotReservation::class)($held, 'user:1', 'operator');
        $this->pinCreatedAt();
        $this->assertNull(PlotReservation::activeForPlot($plot));
    }

    /**
     * The same-plot re-hold: with the removed `plot_reservations_active_
     * hold` partial unique index this was impossible (append-only rows
     * never released the first `held` row's entry — a plot could only
     * ever be reserved once); the corrected mechanism (plot-row lock +
     * `plot_state` aggregate) allows the re-hold, and the head must
     * resolve to the NEW held row by identity.
     */
    public function test_same_plot_can_be_reheld_after_expiry_and_is_the_new_head(): void
    {
        $plot = $this->plot('001');
        $order = $this->order();

        $held = app(ReservePlot::class)($plot, $order, 'user:1', 'operator');
        app(ExpirePlotReservation::class)($held, 'user:1', 'operator');
        $this->pinCreatedAt();
        $this->assertNull(PlotReservation::activeForOrder($order));

        $reheld = app(ReservePlot::class)($plot, $order, 'user:1', 'operator');
        $this->pinCreatedAt();

        $head = PlotReservation::activeForOrder($order);
        $this->assertNotNull($head);
        $this->assertSame($reheld->getKey(), $head->getKey(), 'the head must be the NEW held row, not the superseded one');
        $this->assertSame(PlotReservationState::HELD, $head->state);
        $this->assertSame(PlotState::RESERVED, $plot->fresh()->plot_state);
        $this->assertCount(3, PlotReservation::query()->where('order_id', $order->getKey())->get());
    }
}
