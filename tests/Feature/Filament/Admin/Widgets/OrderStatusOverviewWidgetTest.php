<?php

declare(strict_types=1);

namespace Tests\Feature\Filament\Admin\Widgets;

use App\Domain\Marketplace\Models\MarketplaceOrder;
use App\Domain\Marketplace\Models\Vendor;
use App\Domain\OrderWorkflow\Models\Order;
use App\Domain\OrderWorkflow\OrderStatus;
use App\Domain\OrderWorkflow\ProductType;
use App\Domain\Renewal\Models\Renewal;
use App\Domain\Renewal\RenewalStatus;
use App\Filament\Admin\Widgets\OrderStatusOverviewWidget;
use App\Models\User;
use App\Platform\IdentityAccess\Roles\ActorRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\GrantsActorRoles;
use Tests\TestCase;

/**
 * ADM-001 AC1 — "transaction" and "order status" dashboard modules
 * (`OrderStatusOverviewWidget`). Gated identically to
 * `BookingOrderResource`/`MarketplaceOrderResource`/`RenewalOrderResource`
 * (`MasterDataAdminAuthorizerContract`), and proven against real rows
 * across all three order-bearing domains at once.
 */
final class OrderStatusOverviewWidgetTest extends TestCase
{
    use GrantsActorRoles;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_guests_and_bare_users_cannot_view_the_widget(): void
    {
        $this->assertFalse(OrderStatusOverviewWidget::canView());

        $this->actingAs(User::factory()->create());
        $this->assertFalse(OrderStatusOverviewWidget::canView());
    }

    private function bookingOrder(OrderStatus $status): Order
    {
        return Order::query()->create([
            'reference' => 'MK-2026-'.Str::upper(Str::random(8)),
            'product_type' => ProductType::AT_NEED_SERVICE_ORDER->value,
            'status' => $status->value,
        ]);
    }

    private function marketplaceOrder(string $paymentState): MarketplaceOrder
    {
        $vendor = Vendor::query()->create([
            'name' => 'Vendor Uji Dasbor',
            'is_active' => true,
        ]);

        return MarketplaceOrder::query()->create([
            'order_number' => 'MKT-'.Str::upper(Str::random(8)),
            'customer_ref' => 'customer:widget-test',
            'entity_ref' => 'entity:widget-test',
            'vendor_id' => $vendor->getKey(),
            'subtotal_minor' => 100_000,
            'delivery_fee_minor' => 0,
            'total_minor' => 100_000,
            'payment_state' => $paymentState,
            'idempotency_key' => 'idem-'.Str::random(8),
            'placed_at' => now(),
        ]);
    }

    public function test_it_renders_a_real_status_breakdown_across_all_three_order_domains(): void
    {
        $this->bookingOrder(OrderStatus::MASUK);
        $this->bookingOrder(OrderStatus::MASUK);
        $this->bookingOrder(OrderStatus::SELESAI);

        $this->marketplaceOrder('BELUM_DIBAYAR');
        $this->marketplaceOrder('DIBAYAR');

        Renewal::factory()->create(['status' => RenewalStatus::MENUNGGU_PEMBAYARAN]);
        Renewal::factory()->create(['status' => RenewalStatus::DIBAYAR]);
        Renewal::factory()->create(['status' => RenewalStatus::DIBAYAR]);

        $expectedTotal = Order::query()->count() + MarketplaceOrder::query()->count() + Renewal::query()->count();

        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::OPERATOR);
        $this->actingAs($user);

        Livewire::test(OrderStatusOverviewWidget::class)
            ->assertSee((string) $expectedTotal)
            ->assertSee('Masuk (2)')
            ->assertSee('Selesai (1)')
            ->assertSee('Belum Dibayar (1)')
            ->assertSee('Dibayar (1)')
            ->assertSee('Dibayar (2)');
    }
}
