<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Public\Marketplace;

use App\Domain\Marketplace\AvailabilityMode;
use App\Domain\Marketplace\EvidenceRequirement;
use App\Domain\Marketplace\Models\MarketplaceOrder;
use App\Domain\Marketplace\Models\Product;
use App\Domain\Marketplace\Models\Vendor;
use App\Domain\Marketplace\Models\VendorListing;
use App\Domain\Marketplace\PaymentState;
use App\Domain\Marketplace\ProductCode;
use App\Domain\Marketplace\VendorProcessingStatus;
use App\Livewire\Public\Marketplace\OrderTracking;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * MKT-01 — `OrderTracking`'s IDOR-shaped properties.
 *
 * `$orderNumber` and `$customerRef` decide which order the page (and
 * `fileComplaint()`) resolves against. Before this fix neither carried
 * `#[Locked]`: a forged `/livewire/update` request setting `customerRef`
 * to a STRANGER's real customer reference would have let a guest read that
 * stranger's order status and file a complaint against it —
 * `Livewire::test(...)->set(...)` exercises the exact same
 * `HandleComponents::updateProperties()` synthesizer path a real forged
 * `/livewire/update` HTTP request goes through, so this is a genuine proof
 * of the guard, not a unit test calling PHP property assignment directly.
 */
final class AuthzIdorProbeTest extends TestCase
{
    use RefreshDatabase;

    private function order(string $customerRef, string $status, string $paymentState): MarketplaceOrder
    {
        $vendor = Vendor::create(['name' => 'Toko Bunga', 'is_active' => true]);
        $listing = VendorListing::create([
            'vendor_id' => $vendor->id,
            'product_id' => Product::findByCode(ProductCode::FLOWER_BOARD)->id,
            'price_minor' => 150_000,
            'price_version' => 1,
            'availability_mode' => AvailabilityMode::STOCKED,
            'stock_quantity' => 10,
            'evidence_requirement' => EvidenceRequirement::NONE,
            'is_active' => true,
        ]);
        $order = MarketplaceOrder::create([
            'order_number' => 'MKT-'.strtoupper(uniqid()),
            'customer_ref' => $customerRef,
            'entity_ref' => 'badan-usaha-test',
            'vendor_id' => $vendor->id,
            'subtotal_minor' => 300_000, 'delivery_fee_minor' => 25_000, 'total_minor' => 325_000,
            'payment_state' => $paymentState,
            'idempotency_key' => uniqid('idem-'),
            'placed_at' => now(),
        ]);
        $order->vendorOrders()->create([
            'uuid' => (string) Str::uuid(),
            'vendor_id' => $vendor->id,
            'listing_id' => $listing->id,
            'customer_name' => 'Pelanggan Uji',
            'customer_phone' => '081234567890',
            'customer_email' => 'pelanggan@example.test',
            'status' => $status,
        ]);

        return $order;
    }

    public function test_order_number_cannot_be_set_from_the_client(): void
    {
        $order = $this->order('cust-owner', VendorProcessingStatus::DIPROSES, PaymentState::BELUM_DIBAYAR);

        $this->expectException(CannotUpdateLockedPropertyException::class);

        Livewire::test(OrderTracking::class, ['orderNumber' => $order->order_number, 'customerRef' => 'cust-owner'])
            ->set('orderNumber', 'MKT-SOMEONE-ELSE');
    }

    public function test_customer_ref_cannot_be_set_from_the_client(): void
    {
        $order = $this->order('cust-owner', VendorProcessingStatus::DIPROSES, PaymentState::BELUM_DIBAYAR);

        $this->expectException(CannotUpdateLockedPropertyException::class);

        Livewire::test(OrderTracking::class, ['orderNumber' => $order->order_number, 'customerRef' => 'cust-intruder'])
            ->set('customerRef', 'cust-owner');
    }

    /**
     * The concrete IDOR this guards: without `#[Locked]`, an intruder
     * mounting the page for a foreign order number could set
     * `customerRef` to the real owner's reference and both view the order
     * and file a complaint against it. With `#[Locked]` the update itself
     * is refused before it ever reaches `fileComplaint()`.
     */
    public function test_a_forged_customer_ref_cannot_be_used_to_file_a_complaint_on_a_foreign_order(): void
    {
        $order = $this->order('cust-owner', VendorProcessingStatus::DIPROSES, PaymentState::BELUM_DIBAYAR);

        $component = Livewire::test(OrderTracking::class, [
            'orderNumber' => $order->order_number,
            'customerRef' => 'cust-intruder',
        ]);

        try {
            $component->set('customerRef', 'cust-owner');
            $this->fail('Expected CannotUpdateLockedPropertyException to be thrown.');
        } catch (CannotUpdateLockedPropertyException) {
            // Expected — the forged update never lands.
        }

        $component
            ->set('complaintReason', 'Komplain palsu dari penyusup lewat properti yang dipalsukan.')
            ->call('fileComplaint');

        $order->refresh();
        $vendorOrder = $order->vendorOrders->first();
        $this->assertNotSame(VendorProcessingStatus::KOMPLAIN, $vendorOrder->status);
    }

    /**
     * Defence-in-depth for the authenticated case (`resolveCustomerRef()`):
     * even a component instance that BELIEVES (bypassing `#[Locked]`
     * entirely, e.g. seeded at construction) it belongs to another
     * customer cannot file a complaint as a signed-in user against an
     * order that isn't that user's — `fileComplaint()` re-derives the
     * acting identity from `auth()->id()`, never from the stored property,
     * whenever the actor is authenticated.
     */
    public function test_an_authenticated_actor_cannot_file_a_complaint_via_a_stale_customer_ref_property(): void
    {
        $attacker = User::factory()->create();
        $this->actingAs($attacker);

        $victimOrder = $this->order('cust-victim', VendorProcessingStatus::DIPROSES, PaymentState::BELUM_DIBAYAR);

        // Seeded directly at construction with the victim's customerRef —
        // the state a bypass of `#[Locked]` would have produced.
        Livewire::test(OrderTracking::class, [
            'orderNumber' => $victimOrder->order_number,
            'customerRef' => 'cust-victim',
        ])
            ->set('complaintReason', 'Komplain palsu memakai identitas korban yang dipalsukan.')
            ->call('fileComplaint');

        $victimOrder->refresh();
        $vendorOrder = $victimOrder->vendorOrders->first();
        $this->assertNotSame(VendorProcessingStatus::KOMPLAIN, $vendorOrder->status);
    }
}
