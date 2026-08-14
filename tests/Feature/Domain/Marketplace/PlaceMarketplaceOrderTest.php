<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Marketplace;

use App\Domain\Marketplace\Actions\AddToCart;
use App\Domain\Marketplace\Actions\PlaceMarketplaceOrder;
use App\Domain\Marketplace\AvailabilityMode;
use App\Domain\Marketplace\EvidenceRequirement;
use App\Domain\Marketplace\Exceptions\BadanUsahaNotConfiguredException;
use App\Domain\Marketplace\Exceptions\CartPricingChangedException;
use App\Domain\Marketplace\Models\Cart;
use App\Domain\Marketplace\Models\Product;
use App\Domain\Marketplace\Models\ServiceArea;
use App\Domain\Marketplace\Models\Vendor;
use App\Domain\Marketplace\Models\VendorListing;
use App\Domain\Marketplace\PaymentState;
use App\Domain\Marketplace\ProductCode;
use App\Domain\Marketplace\VendorProcessingStatus;
use App\Platform\FinancialLedger\Actions\VendorPayable;
use App\Platform\FinancialLedger\Money;
use App\Platform\FinancialLedger\VendorPayableState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

final class PlaceMarketplaceOrderTest extends TestCase
{
    use RefreshDatabase;

    private Vendor $vendor;

    private VendorListing $listing;

    private ServiceArea $area;

    protected function setUp(): void
    {
        parent::setUp();

        config(['marketplace.badan_usaha_ref' => 'badan-usaha-test']);

        $this->vendor = Vendor::create(['name' => 'Toko Bunga', 'is_active' => true]);
        $this->listing = VendorListing::create([
            'vendor_id' => $this->vendor->id,
            'product_id' => Product::findByCode(ProductCode::FLOWER_BOARD)->id,
            'price_minor' => 150_000,
            'price_version' => 1,
            'availability_mode' => AvailabilityMode::STOCKED,
            'stock_quantity' => 10,
            'evidence_requirement' => EvidenceRequirement::PHOTO,
            'is_active' => true,
        ]);
        $this->area = ServiceArea::create([
            'vendor_id' => $this->vendor->id,
            'area_code' => 'JKT-SELATAN',
            'area_label' => 'Jakarta Selatan',
            'delivery_fee_minor' => 25_000,
            'is_active' => true,
        ]);
    }

    private function cartWithTwo(): Cart
    {
        $cart = Cart::create(['customer_ref' => 'cust-1']);
        (new AddToCart)->handle($cart, $this->listing, 2);

        return $cart->fresh();
    }

    private function place(Cart $cart, string $idempotencyKey, ?string $customerRef = 'cust-1')
    {
        return (new PlaceMarketplaceOrder)->handle(
            cart: $cart,
            customerRef: $customerRef,
            area: $this->area,
            idempotencyKey: $idempotencyKey,
            recipientName: 'Budi Santoso',
            recipientPhone: '081234567890',
            recipientEmail: 'budi@example.test',
        );
    }

    public function test_placing_an_order_totals_correctly_and_starts_unpaid(): void
    {
        $order = $this->place($this->cartWithTwo(), 'idem-1');

        $this->assertSame(300_000, (int) $order->subtotal_minor);
        $this->assertSame(25_000, (int) $order->delivery_fee_minor);
        $this->assertEquals(new Money(325_000), $order->total());
        $this->assertSame(PaymentState::BELUM_DIBAYAR, $order->payment_state);
    }

    public function test_the_order_allocates_one_vendor_order_per_listing_line_awaiting_the_vendor(): void
    {
        $order = $this->place($this->cartWithTwo(), 'idem-2');

        $this->assertSame(1, $order->vendorOrders()->count());
        $vendorOrder = $order->vendorOrders()->first();
        $this->assertSame($this->vendor->id, $vendorOrder->vendor_id);
        $this->assertSame($this->listing->id, $vendorOrder->listing_id);
        $this->assertSame(VendorProcessingStatus::MENUNGGU_VENDOR, $vendorOrder->status);
        // L10's vendor_orders.customer_* are NOT NULL and decided at checkout.
        $this->assertSame('Budi Santoso', $vendorOrder->customer_name);
        $this->assertSame('081234567890', $vendorOrder->customer_phone);
        $this->assertSame('budi@example.test', $vendorOrder->customer_email);
    }

    public function test_the_payable_references_the_correct_badan_usaha_and_vendor_and_starts_held(): void
    {
        $order = $this->place($this->cartWithTwo(), 'idem-3');

        // AC10, both halves. Read via the query builder so this test does not
        // depend on the ledger's model class location.
        $payable = DB::table('vendor_payables')
            ->where('source_type', 'marketplace_order')
            ->where('source_id', $order->id)
            ->first();

        $this->assertNotNull($payable);
        $this->assertSame('badan-usaha-test', $payable->entity_ref);
        $this->assertSame($this->vendor->id, $payable->vendor_id);
        $this->assertSame(300_000, (int) $payable->amount_minor);
        // Ruling 2 (14 Aug 2026): a checkout-created payable is HELD — the
        // vendor has fulfilled nothing yet (requirement 12).
        $this->assertSame(VendorPayableState::HELD, $payable->state);
        $this->assertNull($payable->eligible_at);

        // Ruling 2: the assessment is UNATTENDED — the audit row must
        // attribute it to the system, by construction, never to a person.
        $audit = DB::table('audit_events')
            ->where('action', VendorPayable::AUDIT_ACTION_ASSESSED)
            ->where('subject_id', (string) $payable->id)
            ->sole();
        $this->assertSame('system', $audit->actor_role);
        $this->assertNull($audit->actor_ref);
        $this->assertSame('job', $audit->source);
    }

    public function test_a_blank_badan_usaha_fails_closed_and_writes_nothing(): void
    {
        config(['marketplace.badan_usaha_ref' => '']);

        $this->expectException(BadanUsahaNotConfiguredException::class);

        try {
            $this->place($this->cartWithTwo(), 'idem-4');
        } finally {
            $this->assertDatabaseCount('marketplace_orders', 0);
            $this->assertDatabaseCount('vendor_payables', 0);
        }
    }

    public function test_resubmitting_the_same_idempotency_key_returns_the_same_order(): void
    {
        $cart = $this->cartWithTwo();
        $first = $this->place($cart, 'idem-same');
        $second = $this->place($cart->fresh(), 'idem-same');

        $this->assertSame($first->id, $second->id);
        $this->assertDatabaseCount('marketplace_orders', 1);
        $this->assertDatabaseCount('vendor_payables', 1);
    }

    public function test_an_empty_cart_cannot_be_checked_out(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->place(Cart::create(['customer_ref' => 'x']), 'idem-5');
    }

    public function test_a_stale_price_blocks_checkout_rather_than_silently_recharging(): void
    {
        $cart = $this->cartWithTwo();
        $this->listing->update(['price_minor' => 200_000, 'price_version' => 2]);

        $this->expectException(CartPricingChangedException::class);

        $this->place($cart->fresh(), 'idem-6');
    }
}
