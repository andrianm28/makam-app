<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Domain\Marketplace\Models\MarketplaceOrder;
use App\Domain\Marketplace\Models\Vendor;
use App\Filament\Admin\Resources\MarketplaceOrders\Actions\MarkMarketplaceOrderPaidAction;
use App\Filament\Admin\Resources\MarketplaceOrders\MarketplaceOrderResource;
use App\Models\User;
use App\Platform\IdentityAccess\Roles\ActorRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\GrantsActorRoles;
use Tests\TestCase;

final class MarketplaceOrderResourceTest extends TestCase
{
    use GrantsActorRoles;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function order(string $paymentState): MarketplaceOrder
    {
        $vendor = Vendor::query()->create([
            'name' => 'Toko Bunga',
            'is_active' => true,
        ]);

        return MarketplaceOrder::query()->create([
            'order_number' => 'MKT-'.Str::upper(Str::random(8)),
            'customer_ref' => 'customer:1',
            'entity_ref' => 'entity:1',
            'vendor_id' => $vendor->getKey(),
            'subtotal_minor' => 250000,
            'delivery_fee_minor' => 0,
            'total_minor' => 250000,
            'payment_state' => $paymentState,
            'idempotency_key' => 'idem-'.Str::random(8),
            'placed_at' => now(),
        ]);
    }

    public function test_operator_can_access_marketplace_resource(): void
    {
        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::OPERATOR);
        $this->actingAs($user);
        $this->assertTrue(MarketplaceOrderResource::canAccess());
    }

    public function test_operator_cannot_run_mark_paid_action(): void
    {
        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::OPERATOR);
        $this->actingAs($user);

        $order = $this->order('BELUM_DIBAYAR');
        $action = MarkMarketplaceOrderPaidAction::make($order);
        $this->assertFalse($action->isAuthorized());
    }

    public function test_finance_can_run_mark_paid_action(): void
    {
        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::FINANCE);
        $this->actingAs($user);

        $order = $this->order('BELUM_DIBAYAR');
        $action = MarkMarketplaceOrderPaidAction::make($order);
        $this->assertTrue($action->isAuthorized());
    }
}
