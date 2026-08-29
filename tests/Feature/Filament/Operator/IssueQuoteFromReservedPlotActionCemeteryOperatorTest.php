<?php

declare(strict_types=1);

namespace Tests\Feature\Filament\Operator;

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
use App\Filament\Operator\Resources\CemeteryOrders\Pages\ViewCemeteryOrder;
use App\Models\User;
use App\Platform\IdentityAccess\Roles\ActorRole;
use App\Platform\IdentityAccess\Scopes\Models\ScopeAssignment;
use App\Platform\IdentityAccess\Scopes\ScopeEntityType;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\GrantsActorRoles;
use Tests\TestCase;

/**
 * Operator-panel coverage for the shortcut button (Phase F, Task 2, Step 7):
 * proves it also works through `/operator`'s `ViewCemeteryOrder`, for a
 * `cemetery_operator` actor holding a grant for the order's own cemetery —
 * mirroring `IssueQuoteFromReservedPlotActionTest`'s admin-panel test, and
 * the `ScopeAssignment` fixture pattern established by
 * `ReservePlotActionCemeteryOperatorTest`.
 */
final class IssueQuoteFromReservedPlotActionCemeteryOperatorTest extends TestCase
{
    use GrantsActorRoles;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Filament::setCurrentPanel('operator');
    }

    private function makePricedService(): ServiceDefinition
    {
        return ServiceDefinition::findByCode(ServiceCode::GRAVE_DIGGING);
    }

    private function makeGranularCemetery(): Cemetery
    {
        return Cemetery::query()->create([
            'type' => CemeteryType::TPU,
            'publication_status' => CemeteryPublicationStatus::PUBLISHED,
            'name' => 'TPU Uji Coba Operator',
            'slug' => 'tpu-uji-coba-operator-'.Str::lower(Str::random(6)),
            'city' => LaunchCityCode::JAKARTA,
            'address' => 'Jl. Contoh No. 1',
            'plot_tracking_mode' => PlotTrackingMode::GRANULAR,
        ]);
    }

    private function makePlotIn(Cemetery $cemetery): GravePlot
    {
        $block = CemeteryBlock::query()->create(['cemetery_id' => $cemetery->getKey(), 'code' => 'BLOK-A', 'name' => 'Blok A', 'capacity' => 1]);

        return GravePlot::query()->create(['block_id' => $block->getKey(), 'slot' => '001', 'plot_state' => 'available']);
    }

    private function makeOrder(Cemetery $cemetery): Order
    {
        $service = $this->makePricedService();
        $draft = BookingDraft::query()->create([
            'cemetery_id' => $cemetery->getKey(),
            'service_type' => BookingServiceType::NEW_GRAVE,
            'selected_services' => [['code' => $service->code, 'quantity' => 1]],
            'customer_full_name' => 'UAT Penerima Operator',
        ]);

        return Order::query()->create([
            'reference' => 'MK-2026-'.Str::upper(Str::random(8)),
            'product_type' => ProductType::AT_NEED_SERVICE_ORDER->value,
            'status' => OrderStatus::DIVERIFIKASI->value,
            'booking_draft_id' => $draft->getKey(),
        ]);
    }

    private function actingAsCemeteryOperatorGrantedTo(Cemetery $cemetery): User
    {
        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::CEMETERY_OPERATOR);
        ScopeAssignment::query()->create([
            'actor_identifier' => (string) $user->id,
            'entity_type' => ScopeEntityType::CEMETERY,
            'entity_id' => (string) $cemetery->id,
        ]);
        $this->actingAs($user);
        $this->app->forgetScopedInstances();

        return $user;
    }

    public function test_a_cemetery_operator_granted_the_orders_cemetery_can_use_the_shortcut_from_the_operator_panel(): void
    {
        $cemetery = $this->makeGranularCemetery();
        $order = $this->makeOrder($cemetery);
        $plot = $this->makePlotIn($cemetery);

        $user = $this->actingAsCemeteryOperatorGrantedTo($cemetery);
        app(ReservePlot::class)($plot, $order, (string) $user->getKey(), 'cemetery_operator');

        Livewire::test(ViewCemeteryOrder::class, ['record' => $order->getRouteKey()])
            ->assertActionVisible('issue_quote_from_reserved_plot')
            ->callAction('issue_quote_from_reserved_plot');

        $this->assertSame(OrderStatus::PENAWARAN_TERKIRIM, $order->fresh()->status());
        $this->assertNotNull(Quote::currentFor($order->fresh()));
    }

    public function test_the_generic_auto_rendered_transition_button_never_appears_for_this_edge_on_the_operator_panel(): void
    {
        // The operator-panel half of the admin-panel regression in
        // IssueQuoteFromReservedPlotActionTest. ViewCemeteryOrder carries its
        // OWN copy of the `continue` guard that skips the generic per-edge
        // factory for DIVERIFIKASI -> PENAWARAN_TERKIRIM; before this test,
        // deleting that copy left the whole suite green. It matters most on
        // this panel, where the actor is the lower-privilege
        // cemetery_operator.
        //
        // assertActionDoesNotExist(), not assertActionHidden(): the loop
        // `continue`s past the pair entirely, so the action is never
        // registered and has no visible()/authorize() to fail. Same reasoning
        // and same idiom as the admin-panel test.
        $cemetery = $this->makeGranularCemetery();
        $order = $this->makeOrder($cemetery);
        $plot = $this->makePlotIn($cemetery);

        $user = $this->actingAsCemeteryOperatorGrantedTo($cemetery);
        app(ReservePlot::class)($plot, $order, (string) $user->getKey(), 'cemetery_operator');

        Livewire::test(ViewCemeteryOrder::class, ['record' => $order->getRouteKey()])
            ->assertActionDoesNotExist('transition_PENAWARAN_TERKIRIM');
    }
}
