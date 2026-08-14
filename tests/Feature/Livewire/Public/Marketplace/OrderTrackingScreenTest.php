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
use App\Support\Design\StatusIntent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

final class OrderTrackingScreenTest extends TestCase
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

    public function test_the_customer_sees_the_current_vendor_processing_status(): void
    {
        $order = $this->order('cust-1', VendorProcessingStatus::DIPROSES, PaymentState::BELUM_DIBAYAR);

        Livewire::test(OrderTracking::class, ['orderNumber' => $order->order_number, 'customerRef' => 'cust-1'])
            ->assertOk()
            ->assertSee(StatusIntent::label(VendorProcessingStatus::DIPROSES, StatusIntent::FAMILY_VENDOR_PROCESSING));
    }

    public function test_a_paid_order_is_never_shown_as_fulfilment_complete(): void
    {
        // AC12: paid, but the vendor has not finished.
        $order = $this->order('cust-1', VendorProcessingStatus::DIPROSES, PaymentState::DIBAYAR);

        $component = Livewire::test(OrderTracking::class, [
            'orderNumber' => $order->order_number, 'customerRef' => 'cust-1',
        ]);

        // Two separate indicators, both visible.
        $component->assertSee('Pembayaran');
        $component->assertSee('Proses vendor');
        $component->assertSee(StatusIntent::label(VendorProcessingStatus::DIPROSES, StatusIntent::FAMILY_VENDOR_PROCESSING));
        // The fulfilment-complete label must NOT appear.
        $component->assertDontSee(StatusIntent::label(VendorProcessingStatus::SELESAI, StatusIntent::FAMILY_VENDOR_PROCESSING));
    }

    public function test_every_vendor_status_resolves_through_status_intent(): void
    {
        foreach (VendorProcessingStatus::KNOWN_STATUSES as $status) {
            $order = $this->order('cust-loop', $status, PaymentState::BELUM_DIBAYAR);

            Livewire::test(OrderTracking::class, [
                'orderNumber' => $order->order_number, 'customerRef' => 'cust-loop',
            ])->assertOk()->assertSee(StatusIntent::label($status, StatusIntent::FAMILY_VENDOR_PROCESSING));
        }
    }

    public function test_a_pending_status_is_never_styled_as_success(): void
    {
        $this->assertSame(
            StatusIntent::INTENT_PENDING,
            StatusIntent::intent(VendorProcessingStatus::MENUNGGU_VENDOR, StatusIntent::FAMILY_VENDOR_PROCESSING)
        );
    }

    public function test_another_customers_order_is_indistinguishable_from_one_that_never_existed(): void
    {
        $order = $this->order('cust-owner', VendorProcessingStatus::DIPROSES, PaymentState::BELUM_DIBAYAR);

        $forbidden = Livewire::test(OrderTracking::class, [
            'orderNumber' => $order->order_number, 'customerRef' => 'cust-intruder',
        ]);
        $missing = Livewire::test(OrderTracking::class, [
            'orderNumber' => 'MKT-DOESNOTEXIST', 'customerRef' => 'cust-intruder',
        ]);

        foreach ([$forbidden, $missing] as $component) {
            $component->assertOk()->assertSee('Pesanan tidak ditemukan');
            $component->assertDontSee('Toko Bunga');
        }
    }
}
