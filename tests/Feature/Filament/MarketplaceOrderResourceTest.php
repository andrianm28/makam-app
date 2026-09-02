<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Domain\Marketplace\AvailabilityMode;
use App\Domain\Marketplace\EvidenceRequirement;
use App\Domain\Marketplace\Models\MarketplaceOrder;
use App\Domain\Marketplace\Models\MarketplaceOrderItem;
use App\Domain\Marketplace\Models\Product;
use App\Domain\Marketplace\Models\Vendor;
use App\Domain\Marketplace\Models\VendorListing;
use App\Domain\Marketplace\ProductCode;
use App\Filament\Admin\Resources\MarketplaceOrders\Actions\MarkMarketplaceOrderPaidAction;
use App\Filament\Admin\Resources\MarketplaceOrders\MarketplaceOrderResource;
use App\Filament\Admin\Resources\MarketplaceOrders\Pages\ViewMarketplaceOrder;
use App\Models\User;
use App\Platform\IdentityAccess\Roles\ActorRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
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

    /**
     * 2 Sep 2026 UAT finding: the infolist's "Produk" entry only resolved
     * `items.variant.product.name`, but `product_variant_id` is only ever
     * set for a variant-based product — a real checkout for a plain
     * product (no variants) leaves it null and only sets
     * `vendor_listing_id`/`product_id`, which rendered as a blank
     * "Produk: —" on every such item. Reproduced live on a real order for
     * "Karangan Bunga Papan" (a non-variant product).
     */
    public function test_the_view_page_resolves_the_product_name_for_a_non_variant_item(): void
    {
        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::ADMIN);
        $this->actingAs($user);

        $order = $this->order('BELUM_DIBAYAR');
        $vendor = Vendor::find($order->vendor_id);
        $product = Product::findByCode(ProductCode::FLOWER_BOARD);
        $listing = VendorListing::create([
            'vendor_id' => $vendor->id,
            'product_id' => $product->id,
            'price_minor' => 650_000,
            'price_version' => 1,
            'availability_mode' => AvailabilityMode::STOCKED,
            'stock_quantity' => 10,
            'evidence_requirement' => EvidenceRequirement::NONE,
            'is_active' => true,
        ]);

        MarketplaceOrderItem::query()->create([
            'marketplace_order_id' => $order->getKey(),
            'vendor_listing_id' => $listing->id,
            'product_id' => $product->id,
            'product_variant_id' => null,
            'quantity' => 1,
            'unit_price_minor' => 650_000,
            'line_total_minor' => 650_000,
            'price_version' => 1,
        ]);

        Livewire::test(ViewMarketplaceOrder::class, ['record' => $order->getKey()])
            ->assertOk()
            ->assertSee($product->name);
    }

    /**
     * 2 Sep 2026 UAT finding: "Pelanggan" rendered the raw `customer_ref`
     * value (e.g. a bare user id like "3") instead of a name, reproduced
     * live. Proves both halves: a real user id resolves to their name, and
     * a non-resolvable ref (the existing "customer:1" fixture shape) still
     * falls back to the raw ref rather than breaking.
     */
    public function test_the_view_page_resolves_customer_ref_to_a_real_users_name(): void
    {
        $admin = User::factory()->create();
        $this->grantRoleTo($admin, ActorRole::ADMIN);
        $this->actingAs($admin);

        $customer = User::factory()->create(['name' => 'Budi Santoso']);
        $order = $this->order('BELUM_DIBAYAR');
        $order->forceFill(['customer_ref' => (string) $customer->id])->save();

        Livewire::test(ViewMarketplaceOrder::class, ['record' => $order->getKey()])
            ->assertOk()
            ->assertSee('Budi Santoso');
    }

    public function test_an_unresolvable_customer_ref_falls_back_to_the_raw_value(): void
    {
        $admin = User::factory()->create();
        $this->grantRoleTo($admin, ActorRole::ADMIN);
        $this->actingAs($admin);

        $order = $this->order('BELUM_DIBAYAR');

        Livewire::test(ViewMarketplaceOrder::class, ['record' => $order->getKey()])
            ->assertOk()
            ->assertSee('customer:1');
    }
}
