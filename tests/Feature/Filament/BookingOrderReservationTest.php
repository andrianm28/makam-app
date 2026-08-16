<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Domain\Booking\BookingServiceType;
use App\Domain\Booking\Models\BookingDraft;
use App\Domain\CemeteryCapability\CemeteryPackageAvailabilityStatus;
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
use App\Domain\PlotReservation\Actions\ReservePlot;
use App\Domain\PlotReservation\Models\PlotReservation;
use App\Domain\PlotReservation\PlotReservationState;
use App\Filament\Admin\Resources\BookingOrders\Actions\PlotReservationLifecycleActions;
use App\Filament\Admin\Resources\BookingOrders\Actions\ReservePlotAction;
use App\Filament\Admin\Resources\BookingOrders\Pages\ViewBookingOrder;
use App\Models\User;
use App\Platform\IdentityAccess\Roles\ActorRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\GrantsActorRoles;
use Tests\TestCase;

/**
 * The P3 booking integration (plan Task 5, Lane 3): the 'Reservasi Plot'
 * header action on `ViewBookingOrder` plus the three lifecycle actions and
 * the 'Reservasi' infolist section.
 *
 * The fixture builds the cemetery + block + plots with direct model creates
 * per the plan's Task 1 shapes and links the order to a draft carrying the
 * cemetery (the P1 `AdminOperatorActionsTest` order fixture). The reserved-
 * plot assertions check the RESULT of the Filament action path — the
 * `plot_reservations` row AND the plot state flip — not the domain Action's
 * own contract, which Lane 2's tests own.
 *
 * Lane note: this file runs against Lane 1/2 classes that land at merge
 * time (merge order 1 -> 2 -> 3); the class references below are written
 * against the plan signatures exactly.
 */
final class BookingOrderReservationTest extends TestCase
{
    use GrantsActorRoles;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    /**
     * @return array{cemetery: Cemetery, block: CemeteryBlock, plot1: GravePlot, plot2: GravePlot, order: Order}
     */
    private function fixture(OrderStatus $status = OrderStatus::DIVERIFIKASI): array
    {
        $cemetery = Cemetery::create([
            'type' => CemeteryType::TPU,
            'publication_status' => CemeteryPublicationStatus::PUBLISHED,
            'name' => 'TPU Uji Reservasi',
            'slug' => 'tpu-uji-reservasi',
            'city' => LaunchCityCode::JAKARTA,
            'address' => 'Jl. Uji Coba No. 1',
        ]);

        $block = CemeteryBlock::query()->create([
            'cemetery_id' => $cemetery->getKey(),
            'code' => 'BLOK-A',
            'name' => 'Blok A',
            'capacity' => 2,
        ]);

        $plot1 = GravePlot::query()->create([
            'block_id' => $block->getKey(),
            'slot' => '001',
            'plot_state' => PlotState::AVAILABLE,
        ]);

        $plot2 = GravePlot::query()->create([
            'block_id' => $block->getKey(),
            'slot' => '002',
            'plot_state' => PlotState::AVAILABLE,
        ]);

        $draft = BookingDraft::query()->create([
            'cemetery_id' => $cemetery->getKey(),
            'service_type' => BookingServiceType::NEW_GRAVE,
            'customer_full_name' => 'UAT Pemesan',
        ]);

        $order = Order::query()->create([
            'reference' => 'MK-2026-'.Str::upper(Str::random(8)),
            'product_type' => ProductType::AT_NEED_SERVICE_ORDER->value,
            'status' => $status->value,
            'booking_draft_id' => $draft->getKey(),
        ]);

        return compact('cemetery', 'block', 'plot1', 'plot2', 'order');
    }

    public function test_reserve_action_is_visible_at_menunggu_ketersediaan(): void
    {
        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::OPERATOR);
        $this->actingAs($user);

        ['order' => $order] = $this->fixture(OrderStatus::MENUNGGU_KETERSEDIAAN);

        Livewire::test(ViewBookingOrder::class, ['record' => $order->getRouteKey()])
            ->assertActionVisible('reserve_plot');
    }

    public function test_finance_role_is_denied_the_lifecycle_actions(): void
    {
        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::FINANCE);
        $this->actingAs($user);

        ['plot1' => $plot, 'order' => $order] = $this->fixture();
        app(ReservePlot::class)($plot, $order, 'user:1', 'operator');

        $reservation = PlotReservation::activeForOrder($order);
        $this->assertNotNull($reservation);

        $this->assertFalse(PlotReservationLifecycleActions::confirm($order, $reservation)->isAuthorized());
        $this->assertFalse(PlotReservationLifecycleActions::release($order, $reservation)->isAuthorized());
        $this->assertFalse(PlotReservationLifecycleActions::expire($order, $reservation)->isAuthorized());

        Livewire::test(ViewBookingOrder::class, ['record' => $order->getRouteKey()])
            ->assertActionHidden('confirm_plot_reservation')
            ->assertActionHidden('release_plot_reservation')
            ->assertActionHidden('expire_plot_reservation');
    }

    public function test_operator_reserves_an_available_plot_from_the_order_view(): void
    {
        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::OPERATOR);
        $this->actingAs($user);

        ['plot1' => $plot, 'order' => $order] = $this->fixture();

        Livewire::test(ViewBookingOrder::class, ['record' => $order->getRouteKey()])
            ->assertActionVisible('reserve_plot')
            ->callAction('reserve_plot', data: ['plot_id' => $plot->getKey()])
            ->assertNotified('Plot berhasil direservasi.');

        $this->assertDatabaseHas('plot_reservations', [
            'plot_id' => $plot->getKey(),
            'order_id' => $order->getKey(),
            'state' => PlotReservationState::HELD,
        ]);
        $this->assertSame(PlotState::RESERVED, $plot->fresh()->plot_state);
    }

    public function test_reserve_action_options_are_filtered_by_the_draft_package_class(): void
    {
        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::OPERATOR);
        $this->actingAs($user);

        $cemetery = Cemetery::create([
            'type' => CemeteryType::TPU,
            'publication_status' => CemeteryPublicationStatus::PUBLISHED,
            'name' => 'TPU Uji Kelas',
            'slug' => 'tpu-uji-kelas',
            'city' => LaunchCityCode::JAKARTA,
            'address' => 'Jl. Uji Coba No. 2',
        ]);
        $package = $cemetery->packages()->create([
            'name' => 'Paket Utama',
            'availability_status' => CemeteryPackageAvailabilityStatus::DEFAULT,
            'is_active' => true,
        ]);
        $block = CemeteryBlock::query()->create([
            'cemetery_id' => $cemetery->getKey(),
            'code' => 'BLOK-B',
            'name' => 'Blok B',
            'capacity' => 2,
        ]);
        GravePlot::query()->create([
            'block_id' => $block->getKey(),
            'slot' => '001',
            'plot_state' => PlotState::AVAILABLE,
            'cemetery_package_id' => $package->getKey(),
        ]);
        GravePlot::query()->create([
            'block_id' => $block->getKey(),
            'slot' => '002',
            'plot_state' => PlotState::AVAILABLE,
        ]);
        GravePlot::query()->create([
            'block_id' => $block->getKey(),
            'slot' => '003',
            'plot_state' => PlotState::OCCUPIED,
            'cemetery_package_id' => $package->getKey(),
        ]);
        $draft = BookingDraft::query()->create([
            'cemetery_id' => $cemetery->getKey(),
            'cemetery_package_id' => $package->getKey(),
            'service_type' => BookingServiceType::NEW_GRAVE,
        ]);
        $order = Order::query()->create([
            'reference' => 'MK-2026-'.Str::upper(Str::random(8)),
            'product_type' => ProductType::AT_NEED_SERVICE_ORDER->value,
            'status' => OrderStatus::DIVERIFIKASI->value,
            'booking_draft_id' => $draft->getKey(),
        ]);

        Livewire::test(ViewBookingOrder::class, ['record' => $order->getRouteKey()])
            ->mountAction('reserve_plot')
            ->assertMountedActionModalSee('BLOK-B — 001')
            ->assertMountedActionModalDontSee('BLOK-B — 002')
            ->assertMountedActionModalDontSee('BLOK-B — 003');
    }

    public function test_reserve_action_is_hidden_outside_the_reservable_statuses(): void
    {
        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::OPERATOR);
        $this->actingAs($user);

        $cemetery = Cemetery::create([
            'type' => CemeteryType::TPU,
            'publication_status' => CemeteryPublicationStatus::PUBLISHED,
            'name' => 'TPU Uji Status',
            'slug' => 'tpu-uji-status',
            'city' => LaunchCityCode::JAKARTA,
            'address' => 'Jl. Uji Coba No. 3',
        ]);
        $draft = BookingDraft::query()->create([
            'cemetery_id' => $cemetery->getKey(),
            'service_type' => BookingServiceType::NEW_GRAVE,
        ]);
        $order = Order::query()->create([
            'reference' => 'MK-2026-'.Str::upper(Str::random(8)),
            'product_type' => ProductType::AT_NEED_SERVICE_ORDER->value,
            'status' => OrderStatus::MASUK->value,
            'booking_draft_id' => $draft->getKey(),
        ]);

        Livewire::test(ViewBookingOrder::class, ['record' => $order->getRouteKey()])
            ->assertActionHidden('reserve_plot');
    }

    public function test_reserve_action_is_hidden_when_the_draft_cemetery_does_not_resolve(): void
    {
        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::OPERATOR);
        $this->actingAs($user);

        $draft = BookingDraft::query()->create([
            'service_type' => BookingServiceType::NEW_GRAVE,
        ]);
        $order = Order::query()->create([
            'reference' => 'MK-2026-'.Str::upper(Str::random(8)),
            'product_type' => ProductType::AT_NEED_SERVICE_ORDER->value,
            'status' => OrderStatus::DIVERIFIKASI->value,
            'booking_draft_id' => $draft->getKey(),
        ]);

        Livewire::test(ViewBookingOrder::class, ['record' => $order->getRouteKey()])
            ->assertActionHidden('reserve_plot');
    }

    public function test_finance_role_is_denied_the_reserve_action(): void
    {
        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::FINANCE);
        $this->actingAs($user);

        ['order' => $order] = $this->fixture();

        $this->assertFalse(ReservePlotAction::make($order)->isAuthorized());

        Livewire::test(ViewBookingOrder::class, ['record' => $order->getRouteKey()])
            ->assertActionHidden('reserve_plot');
    }

    public function test_operator_restricted_admin_and_admin_roles_are_allowed_the_reserve_action(): void
    {
        ['order' => $order] = $this->fixture();

        foreach ([ActorRole::OPERATOR, ActorRole::RESTRICTED_ADMIN, ActorRole::ADMIN] as $role) {
            $user = User::factory()->create();
            $this->grantRoleTo($user, $role);
            $this->actingAs($user);

            $this->assertTrue(ReservePlotAction::make($order)->isAuthorized(), "role {$role} should authorize the reserve action");
        }
    }

    public function test_lifecycle_actions_follow_the_active_reservation_state(): void
    {
        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::OPERATOR);
        $this->actingAs($user);

        ['plot1' => $plot, 'order' => $order] = $this->fixture();
        app(ReservePlot::class)($plot, $order, 'user:1', 'operator');

        Livewire::test(ViewBookingOrder::class, ['record' => $order->getRouteKey()])
            ->assertActionHidden('reserve_plot')
            ->assertActionVisible('confirm_plot_reservation')
            ->assertActionVisible('release_plot_reservation')
            ->assertActionVisible('expire_plot_reservation')
            ->callAction('confirm_plot_reservation')
            ->assertNotified('Reservasi dikonfirmasi.');

        $this->assertDatabaseHas('plot_reservations', [
            'plot_id' => $plot->getKey(),
            'order_id' => $order->getKey(),
            'state' => PlotReservationState::CONFIRMED,
        ]);

        Livewire::test(ViewBookingOrder::class, ['record' => $order->getRouteKey()])
            ->assertActionHidden('confirm_plot_reservation')
            ->assertActionVisible('release_plot_reservation')
            ->assertActionHidden('expire_plot_reservation')
            ->callAction('release_plot_reservation')
            ->assertNotified('Reservasi dilepas.');

        $this->assertSame(PlotState::AVAILABLE, $plot->fresh()->plot_state);

        Livewire::test(ViewBookingOrder::class, ['record' => $order->getRouteKey()])
            ->assertActionVisible('reserve_plot')
            ->assertActionDoesNotExist('confirm_plot_reservation')
            ->assertActionDoesNotExist('release_plot_reservation')
            ->assertActionDoesNotExist('expire_plot_reservation');
    }

    public function test_expire_restores_plot_availability_and_reopens_reservation(): void
    {
        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::OPERATOR);
        $this->actingAs($user);

        ['plot1' => $plot, 'order' => $order] = $this->fixture();
        app(ReservePlot::class)($plot, $order, 'user:1', 'operator');

        Livewire::test(ViewBookingOrder::class, ['record' => $order->getRouteKey()])
            ->assertActionVisible('expire_plot_reservation')
            ->callAction('expire_plot_reservation')
            ->assertNotified('Reservasi kedaluwarsa.');

        $this->assertDatabaseHas('plot_reservations', [
            'plot_id' => $plot->getKey(),
            'order_id' => $order->getKey(),
            'state' => PlotReservationState::EXPIRED,
        ]);
        $this->assertSame(PlotState::AVAILABLE, $plot->fresh()->plot_state);

        Livewire::test(ViewBookingOrder::class, ['record' => $order->getRouteKey()])
            ->assertActionVisible('reserve_plot')
            ->assertActionDoesNotExist('confirm_plot_reservation')
            ->assertActionDoesNotExist('release_plot_reservation')
            ->assertActionDoesNotExist('expire_plot_reservation');
    }
}
