<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Domain\Booking\Models\BookingDraft;
use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Domain\OrderWorkflow\Models\Order;
use App\Domain\OrderWorkflow\OrderStatus;
use App\Domain\OrderWorkflow\ProductType;
use App\Domain\PlotInventory\Models\CemeteryBlock;
use App\Domain\PlotInventory\Models\GravePlot;
use App\Domain\PlotInventory\PlotState;
use App\Domain\PlotReservation\Models\PlotReservation;
use App\Domain\PlotReservation\PlotReservationState;
use App\Filament\Admin\Resources\BookingOrders\Pages\ListBookingOrders;
use App\Filament\Admin\Resources\BookingOrders\Tables\BookingOrdersTable;
use App\Models\User;
use App\Platform\IdentityAccess\Roles\ActorRole;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\GrantsActorRoles;
use Tests\TestCase;

/**
 * The redesigned shared order table — columns, the two new filters, and the
 * roadmap's explicit "no N+1" requirement, proven against real Postgres rows
 * through the real Livewire table component rather than by inspecting the
 * builder's configuration.
 */
final class BookingOrdersTableTest extends TestCase
{
    use GrantsActorRoles;
    use RefreshDatabase;

    private Cemetery $cemeteryA;

    private Cemetery $cemeteryB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        $this->cemeteryA = Cemetery::factory()->create(['name' => 'TPU Alpha']);
        $this->cemeteryB = Cemetery::factory()->create(['name' => 'TPU Beta']);

        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::OPERATOR);
        $this->actingAs($user);
    }

    private function orderFor(Cemetery $cemetery, string $customer): Order
    {
        $draft = BookingDraft::query()->create([
            'cemetery_id' => $cemetery->id,
            'customer_full_name' => $customer,
        ]);

        return Order::query()->create([
            'reference' => 'MK-2026-'.Str::upper(Str::random(8)),
            'product_type' => ProductType::AT_NEED_SERVICE_ORDER->value,
            'status' => OrderStatus::DIVERIFIKASI->value,
            'booking_draft_id' => $draft->id,
        ]);
    }

    private function holdAPlotFor(Order $order, Cemetery $cemetery, string $slot): PlotReservation
    {
        $block = CemeteryBlock::query()->create([
            'cemetery_id' => $cemetery->id,
            'code' => 'BLOK-'.Str::upper(Str::random(4)),
            'name' => 'Blok uji',
            'capacity' => 5,
            'is_active' => true,
        ]);
        $plot = GravePlot::query()->create([
            'block_id' => $block->id,
            'slot' => $slot,
            'plot_state' => PlotState::RESERVED,
        ]);

        return PlotReservation::query()->create([
            'plot_id' => $plot->id,
            'order_id' => $order->id,
            'state' => PlotReservationState::HELD,
            'reserved_by_ref' => '1',
            'reserved_at' => CarbonImmutable::now(),
        ]);
    }

    public function test_the_table_renders_the_cemetery_and_plot_columns(): void
    {
        $order = $this->orderFor($this->cemeteryA, 'Budi Santoso');
        $this->holdAPlotFor($order, $this->cemeteryA, 'A-001');

        Livewire::test(ListBookingOrders::class)
            ->assertCanSeeTableRecords([$order])
            ->assertTableColumnStateSet('bookingDraft.cemetery.name', 'TPU Alpha', $order)
            ->assertSee('A-001');
    }

    public function test_the_plot_column_is_empty_for_an_order_whose_reservation_was_released(): void
    {
        $order = $this->orderFor($this->cemeteryA, 'Budi Santoso');
        $reservation = $this->holdAPlotFor($order, $this->cemeteryA, 'A-002');
        PlotReservation::query()->create([
            'plot_id' => $reservation->plot_id,
            'order_id' => $order->id,
            'state' => PlotReservationState::RELEASED,
            'reserved_by_ref' => '1',
            'released_at' => CarbonImmutable::now()->addMinute(),
        ]);

        Livewire::test(ListBookingOrders::class)
            ->assertCanSeeTableRecords([$order])
            ->assertDontSee('A-002');
    }

    public function test_the_cemetery_filter_narrows_to_one_cemeterys_orders(): void
    {
        $alpha = $this->orderFor($this->cemeteryA, 'Budi Santoso');
        $beta = $this->orderFor($this->cemeteryB, 'Citra Dewi');

        Livewire::test(ListBookingOrders::class)
            ->filterTable('cemetery', $this->cemeteryA->id)
            ->assertCanSeeTableRecords([$alpha])
            ->assertCanNotSeeTableRecords([$beta]);
    }

    public function test_the_has_reserved_plot_filter_keeps_only_actively_reserved_orders(): void
    {
        $reserved = $this->orderFor($this->cemeteryA, 'Budi Santoso');
        $this->holdAPlotFor($reserved, $this->cemeteryA, 'A-003');

        $unreserved = $this->orderFor($this->cemeteryA, 'Citra Dewi');

        $released = $this->orderFor($this->cemeteryA, 'Dewi Lestari');
        $releasedHold = $this->holdAPlotFor($released, $this->cemeteryA, 'A-004');
        PlotReservation::query()->create([
            'plot_id' => $releasedHold->plot_id,
            'order_id' => $released->id,
            'state' => PlotReservationState::RELEASED,
            'reserved_by_ref' => '1',
            'released_at' => CarbonImmutable::now()->addMinute(),
        ]);

        Livewire::test(ListBookingOrders::class)
            ->filterTable('has_reserved_plot')
            ->assertCanSeeTableRecords([$reserved])
            ->assertCanNotSeeTableRecords([$unreserved, $released]);
    }

    public function test_the_reservation_chain_is_eager_loaded_not_queried_per_row(): void
    {
        foreach (['Budi', 'Citra', 'Dewi', 'Eka'] as $index => $name) {
            $order = $this->orderFor($this->cemeteryA, $name);
            $this->holdAPlotFor($order, $this->cemeteryA, 'A-10'.$index);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();

        Livewire::test(ListBookingOrders::class)->assertOk();

        $reservationQueries = collect(DB::getQueryLog())
            ->filter(fn (array $entry): bool => str_contains($entry['query'], 'plot_reservations'))
            ->count();

        DB::disableQueryLog();

        // One eager load for the whole page. Four orders rendered through a
        // per-row `activeForOrder()` call would make this 4 (or 5 with the
        // filter's own subquery), so this assertion fails loudly the moment
        // the N+1 is reintroduced.
        $this->assertSame(1, $reservationQueries);
    }

    /**
     * `plotLabel()` dereferences `$reservation->plot->block` without a null
     * guard was the finding (M-2 of the final review) — FK constraints
     * (`grave_plots.block_id` is `restrictOnDelete`) make a genuinely
     * orphaned row unreachable through normal writes, so this exercises the
     * guard directly against an in-memory relation rather than trying to
     * manufacture the impossible database state.
     */
    public function test_the_plot_column_degrades_instead_of_fataling_when_the_plot_relation_is_missing(): void
    {
        $order = $this->orderFor($this->cemeteryA, 'Budi Santoso');
        $reservation = $this->holdAPlotFor($order, $this->cemeteryA, 'A-005');
        $reservation->setRelation('plot', null);
        $order->setRelation('plotReservations', new Collection([$reservation]));

        $method = new \ReflectionMethod(BookingOrdersTable::class, 'plotLabel');
        $method->setAccessible(true);

        $this->assertSame('—', $method->invoke(null, $order));
    }
}
