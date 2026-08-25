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
use App\Domain\PlotReservation\Actions\ExpirePlotReservation;
use App\Domain\PlotReservation\Actions\ReservePlot;
use App\Domain\PlotReservation\Exceptions\PlotNotAvailableException;
use App\Domain\PlotReservation\Models\PlotReservation;
use App\Domain\PlotReservation\PlotReservationState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ReservePlotTest extends TestCase
{
    use RefreshDatabase;

    private function cemetery(): Cemetery
    {
        // `Cemetery::factory()` does not exist (no factory class, no
        // `HasFactory` on the model — the plan flagged this as a drift
        // risk to resolve at implementation time), so the fixture is a
        // direct create against the model's saving guards: `type`,
        // `publication_status`, and `city` are the three values the
        // guard validates, `name`/`slug`/`address` are the NOT NULL
        // columns beyond them.
        return Cemetery::query()->create([
            'type' => CemeteryType::TPU,
            'publication_status' => CemeteryPublicationStatus::DRAFT,
            'name' => 'TPU Uji Coba',
            'slug' => 'tpu-uji-coba-'.Str::lower(Str::random(6)),
            'city' => LaunchCityCode::JAKARTA,
            'address' => 'Jl. Contoh No. 1',
        ]);
    }

    private function plot(string $state = PlotState::AVAILABLE): GravePlot
    {
        $cemetery = $this->cemetery();
        $block = CemeteryBlock::query()->create(['cemetery_id' => $cemetery->getKey(), 'code' => 'BLOK-A', 'name' => 'Blok A', 'capacity' => 1]);

        return GravePlot::query()->create(['block_id' => $block->getKey(), 'slot' => '001', 'plot_state' => $state]);
    }

    private function order(): Order
    {
        return Order::query()->create([
            'reference' => 'MK-2026-'.Str::upper(Str::random(8)),
            'product_type' => ProductType::AT_NEED_SERVICE_ORDER->value,
            'status' => OrderStatus::DIVERIFIKASI->value,
        ]);
    }

    public function test_reserves_an_available_plot(): void
    {
        $plot = $this->plot();
        $order = $this->order();

        $reservation = app(ReservePlot::class)($plot, $order, 'user:1', 'operator');

        $this->assertSame(PlotReservationState::HELD, $reservation->state);
        $this->assertSame(PlotState::RESERVED, $plot->fresh()->plot_state);
        $this->assertDatabaseHas('plot_reservations', ['plot_id' => $plot->getKey(), 'order_id' => $order->getKey(), 'state' => 'held']);
        $this->assertDatabaseHas('audit_events', ['action' => 'PLOT_RESERVATION_CREATED']);
        $this->assertDatabaseHas('outbox_events', ['event_name' => 'plot_reservation.state_changed.v1']);
    }

    public function test_order_idempotency_returns_incumbent(): void
    {
        $plot = $this->plot();
        $order = $this->order();
        $first = app(ReservePlot::class)($plot, $order, 'user:1', 'operator');

        $second = app(ReservePlot::class)($plot, $order, 'user:1', 'operator');

        $this->assertSame($first->getKey(), $second->getKey());
        $this->assertSame(1, PlotReservation::query()->count());
    }

    /**
     * Finding I1's regression, in its deterministic (sequential) form:
     * a second claim by the SAME order on a DIFFERENT plot must return
     * the incumbent — and, the NEW assertion, the second plot must stay
     * `available` (before the fix the concurrent variant of this race
     * committed TWO active holds for one order because the pre-check ran
     * outside the transaction and the pair locked different plot rows;
     * the authoritative order-row lock inside the transaction is what
     * prevents it — a sequential session can only reach the pre-check,
     * so this test pins the observable invariant).
     */
    public function test_second_reserve_for_same_order_on_a_different_plot_returns_incumbent_and_keeps_the_plot_available(): void
    {
        $plotA = $this->plot();
        $plotB = $this->plot();
        $order = $this->order();

        $first = app(ReservePlot::class)($plotA, $order, 'user:1', 'operator');

        $second = app(ReservePlot::class)($plotB, $order, 'user:1', 'operator');

        $this->assertSame($first->getKey(), $second->getKey());
        $this->assertSame(PlotState::RESERVED, $plotA->fresh()->plot_state);
        $this->assertSame(PlotState::AVAILABLE, $plotB->fresh()->plot_state);
        $this->assertSame(1, PlotReservation::query()->count());
    }

    public function test_occupied_plot_is_refused(): void
    {
        $plot = $this->plot(PlotState::OCCUPIED);
        $order = $this->order();

        $this->expectException(PlotNotAvailableException::class);
        app(ReservePlot::class)($plot, $order, 'user:1', 'operator');
    }

    /**
     * The plot-state path of the one-active-hold invariant (the
     * `plot_reservations_active_hold` index that used to backstop it was
     * removed — see `ReservePlot`'s class doc block): a plot whose
     * `plot_state` is `reserved` — here set manually, WITHOUT any
     * reservation row — is refused by the state assert under the plot
     * row lock, before any insert is attempted.
     */
    public function test_reserved_plot_without_reservation_row_is_refused(): void
    {
        $plot = $this->plot(PlotState::RESERVED);
        $order = $this->order();

        $this->expectException(PlotNotAvailableException::class);
        app(ReservePlot::class)($plot, $order, 'user:1', 'operator');
    }

    /**
     * The regression test for the removed backstop index: a plot that
     * was held and then expired CAN be reserved again. With the old
     * `plot_reservations_active_hold` partial unique index this threw
     * `PlotReservationConflictException` — append-only rows never
     * released the first `held` row's index entry, so a plot could only
     * ever be reserved once. The old chain is preserved append-only; the
     * new hold is a NEW row.
     */
    public function test_plot_can_be_reserved_again_after_expire(): void
    {
        $plot = $this->plot();
        $order = $this->order();

        $first = app(ReservePlot::class)($plot, $order, 'user:1', 'operator');
        app(ExpirePlotReservation::class)($first, 'user:1', 'operator');
        $this->assertSame(PlotState::AVAILABLE, $plot->fresh()->plot_state);

        $second = app(ReservePlot::class)($plot, $order, 'user:1', 'operator');

        $this->assertNotSame($first->getKey(), $second->getKey());
        $this->assertSame(PlotReservationState::HELD, $second->state);
        $this->assertSame(PlotState::RESERVED, $plot->fresh()->plot_state);
        $this->assertSame(3, PlotReservation::query()->count());
        $this->assertSame(
            ['held', 'expired', 'held'],
            PlotReservation::query()
                ->orderBy('created_at')
                ->orderBy('id')
                ->pluck('state')
                ->all(),
        );
    }
}
