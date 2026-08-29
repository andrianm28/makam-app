<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Domain\Booking\BookingServiceType;
use App\Domain\Booking\Models\BookingDraft;
use App\Domain\CemeteryDirectory\CemeteryPublicationStatus;
use App\Domain\CemeteryDirectory\CemeteryType;
use App\Domain\CemeteryDirectory\LaunchCityCode;
use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Domain\CemeteryDirectory\PlotTrackingMode;
use App\Domain\OrderWorkflow\Models\Order;
use App\Domain\OrderWorkflow\OrderStatus;
use App\Domain\OrderWorkflow\ProductType;
use App\Domain\PlotInventory\Models\CemeteryBlock;
use App\Domain\PlotInventory\Models\GravePlot;
use App\Domain\PlotReservation\Actions\ReservePlot;
use App\Domain\Quotation\Models\Quote;
use App\Domain\ServiceCatalog\Models\ServiceDefinition;
use App\Domain\ServiceCatalog\ServiceCode;
use App\Filament\Admin\Resources\BookingOrders\Pages\ViewBookingOrder;
use App\Models\User;
use App\Platform\IdentityAccess\Roles\ActorRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\GrantsActorRoles;
use Tests\TestCase;

final class IssueQuoteFromReservedPlotActionTest extends TestCase
{
    use GrantsActorRoles;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function makePricedService(): ServiceDefinition
    {
        return ServiceDefinition::findByCode(ServiceCode::GRAVE_DIGGING);
    }

    private function makeDraft(): BookingDraft
    {
        $service = $this->makePricedService();

        return BookingDraft::query()->create([
            'service_type' => BookingServiceType::NEW_GRAVE,
            'selected_services' => [['code' => $service->code, 'quantity' => 1]],
            'customer_full_name' => 'UAT Penerima',
        ]);
    }

    private function makeOrder(OrderStatus $status, ?BookingDraft $draft = null): Order
    {
        return Order::query()->create([
            'reference' => 'MK-2026-'.Str::upper(Str::random(8)),
            'product_type' => ProductType::AT_NEED_SERVICE_ORDER->value,
            'status' => $status->value,
            'booking_draft_id' => $draft?->getKey(),
        ]);
    }

    private function makeGranularCemeteryPlot(): GravePlot
    {
        $cemetery = Cemetery::query()->create([
            'type' => CemeteryType::TPU,
            'publication_status' => CemeteryPublicationStatus::PUBLISHED,
            'name' => 'TPU Uji Coba',
            'slug' => 'tpu-uji-coba-'.Str::lower(Str::random(6)),
            'city' => LaunchCityCode::JAKARTA,
            'address' => 'Jl. Contoh No. 1',
            'plot_tracking_mode' => PlotTrackingMode::GRANULAR,
        ]);
        $block = CemeteryBlock::query()->create(['cemetery_id' => $cemetery->getKey(), 'code' => 'BLOK-A', 'name' => 'Blok A', 'capacity' => 1]);

        return GravePlot::query()->create(['block_id' => $block->getKey(), 'slot' => '001', 'plot_state' => 'available']);
    }

    public function test_the_button_is_visible_when_a_reservation_exists_and_invoking_it_issues_a_quote(): void
    {
        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::OPERATOR);
        $this->actingAs($user);

        $draft = $this->makeDraft();
        $order = $this->makeOrder(OrderStatus::DIVERIFIKASI, $draft);
        $plot = $this->makeGranularCemeteryPlot();
        app(ReservePlot::class)($plot, $order, (string) $user->getKey(), 'operator');

        Livewire::test(ViewBookingOrder::class, ['record' => $order->getRouteKey()])
            ->assertActionVisible('issue_quote_from_reserved_plot')
            ->callAction('issue_quote_from_reserved_plot');

        $this->assertSame(OrderStatus::PENAWARAN_TERKIRIM, $order->fresh()->status());
        $this->assertNotNull(Quote::currentFor($order->fresh()));
    }

    public function test_the_button_is_not_visible_without_a_reservation(): void
    {
        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::OPERATOR);
        $this->actingAs($user);

        $order = $this->makeOrder(OrderStatus::DIVERIFIKASI, $this->makeDraft());

        Livewire::test(ViewBookingOrder::class, ['record' => $order->getRouteKey()])
            ->assertActionHidden('issue_quote_from_reserved_plot');
    }

    public function test_the_button_is_not_visible_at_the_wrong_status_even_with_a_reservation(): void
    {
        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::OPERATOR);
        $this->actingAs($user);

        // MASUK cannot legally hold a reservation via ReservePlotAction's own
        // visible() gate in real UI use, but the domain action has no such
        // restriction — construct this state directly to prove the BUTTON's
        // own visible() gate checks status independently of the reservation.
        $draft = $this->makeDraft();
        $order = $this->makeOrder(OrderStatus::MASUK, $draft);
        $plot = $this->makeGranularCemeteryPlot();
        app(ReservePlot::class)($plot, $order, (string) $user->getKey(), 'operator');

        Livewire::test(ViewBookingOrder::class, ['record' => $order->getRouteKey()])
            ->assertActionHidden('issue_quote_from_reserved_plot');
    }

    public function test_the_normal_request_availability_button_still_renders_alongside_it(): void
    {
        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::OPERATOR);
        $this->actingAs($user);

        $draft = $this->makeDraft();
        $order = $this->makeOrder(OrderStatus::DIVERIFIKASI, $draft);
        $plot = $this->makeGranularCemeteryPlot();
        app(ReservePlot::class)($plot, $order, (string) $user->getKey(), 'operator');

        // Both buttons visible at once — the shortcut is additive, not a
        // replacement (roadmap: "appears alongside, not replacing").
        Livewire::test(ViewBookingOrder::class, ['record' => $order->getRouteKey()])
            ->assertActionVisible('issue_quote_from_reserved_plot')
            ->assertActionVisible('transition_MENUNGGU_KETERSEDIAAN');
    }

    public function test_the_generic_auto_rendered_transition_button_never_appears_for_this_edge(): void
    {
        // Regression for the exact bug this task's own plan text warns
        // about: DIVERIFIKASI's widened allowedFrom() list now includes
        // PENAWARAN_TERKIRIM, but the GENERIC per-edge factory must never
        // render a button for that specific (from, to) pair — only the
        // dedicated action above may.
        //
        // assertActionDoesNotExist(), not assertActionHidden(): the loop
        // `continue`s past this (from, to) pair entirely, so the action is
        // never added to getHeaderActions()'s returned array at all — it
        // has no visible()/authorize() to fail. assertActionHidden() calls
        // assertActionExists() first (vendor/filament/actions/src/Testing/
        // TestsActions.php), which fails for an action that was never
        // registered; assertActionDoesNotExist() is this repo's own
        // established idiom for that case (see
        // tests/Feature/Filament/BookingOrderReservationTest.php's
        // confirm_plot_reservation/release_plot_reservation/
        // expire_plot_reservation assertions when no reservation exists).
        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::OPERATOR);
        $this->actingAs($user);

        $draft = $this->makeDraft();
        $order = $this->makeOrder(OrderStatus::DIVERIFIKASI, $draft);
        $plot = $this->makeGranularCemeteryPlot();
        app(ReservePlot::class)($plot, $order, (string) $user->getKey(), 'operator');

        Livewire::test(ViewBookingOrder::class, ['record' => $order->getRouteKey()])
            ->assertActionDoesNotExist('transition_PENAWARAN_TERKIRIM');
    }

    public function test_an_aggregate_tier_order_never_shows_the_button(): void
    {
        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::OPERATOR);
        $this->actingAs($user);

        // No GravePlot/ReservePlot call at all — aggregate-tier orders have
        // no plot inventory to reserve in the first place.
        $order = $this->makeOrder(OrderStatus::DIVERIFIKASI, $this->makeDraft());

        Livewire::test(ViewBookingOrder::class, ['record' => $order->getRouteKey()])
            ->assertActionHidden('issue_quote_from_reserved_plot');
    }
}
