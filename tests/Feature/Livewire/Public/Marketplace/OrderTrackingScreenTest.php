<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Public\Marketplace;

use App\Domain\Marketplace\AvailabilityMode;
use App\Domain\Marketplace\EvidenceRequirement;
use App\Domain\Marketplace\MarketplaceAuditActions;
use App\Domain\Marketplace\Models\MarketplaceOrder;
use App\Domain\Marketplace\Models\Product;
use App\Domain\Marketplace\Models\Vendor;
use App\Domain\Marketplace\Models\VendorListing;
use App\Domain\Marketplace\Models\VendorOrder;
use App\Domain\Marketplace\PaymentState;
use App\Domain\Marketplace\ProductCode;
use App\Domain\Marketplace\VendorProcessingStatus;
use App\Livewire\Public\Marketplace\OrderTracking;
use App\Platform\Audit\Models\AuditEvent;
use App\Platform\Outbox\Models\OutboxEvent;
use App\Support\Design\StatusIntent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
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

    public function test_a_customer_can_file_a_complaint_on_their_own_order_in_an_eligible_status(): void
    {
        $order = $this->order('cust-1', VendorProcessingStatus::DITERIMA_VENDOR, PaymentState::DIBAYAR);
        $vendorOrder = $order->vendorOrders->first();

        Livewire::test(OrderTracking::class, ['orderNumber' => $order->order_number, 'customerRef' => 'cust-1'])
            ->assertSee('Ajukan komplain')
            ->set('complaintReason', 'Karangan bunga yang dikirim tidak sesuai dengan pesanan.')
            ->call('fileComplaint')
            ->assertHasNoErrors()
            ->assertSee('Komplain terkirim');

        $vendorOrder->refresh();
        $this->assertSame(VendorProcessingStatus::KOMPLAIN, $vendorOrder->status);
        $this->assertSame(
            'Komplain pelanggan: Karangan bunga yang dikirim tidak sesuai dengan pesanan.',
            $vendorOrder->notes,
        );

        $audit = AuditEvent::query()
            ->where('action', MarketplaceAuditActions::ORDER_STATUS_CHANGED)
            ->where('subject_id', (string) $vendorOrder->id)
            ->sole();
        $this->assertSame('customer', $audit->actor_role);
        $this->assertSame(
            ['previous_state' => VendorProcessingStatus::DITERIMA_VENDOR, 'new_state' => VendorProcessingStatus::KOMPLAIN],
            $audit->metadata,
        );

        $outbox = OutboxEvent::query()
            ->where('event_name', 'vendor_order.complaint_filed.v1')
            ->where('aggregate_id', $vendorOrder->getKey())
            ->sole();
        $this->assertEqualsCanonicalizing(
            ['vendor_order_id' => $vendorOrder->getKey(), 'previous_status' => VendorProcessingStatus::DITERIMA_VENDOR, 'filed_by_role' => 'customer'],
            $outbox->payload,
        );
    }

    public function test_filing_a_complaint_requires_a_reason(): void
    {
        $order = $this->order('cust-1', VendorProcessingStatus::DIPROSES, PaymentState::BELUM_DIBAYAR);
        $vendorOrder = $order->vendorOrders->first();

        Livewire::test(OrderTracking::class, ['orderNumber' => $order->order_number, 'customerRef' => 'cust-1'])
            ->set('complaintReason', '')
            ->call('fileComplaint')
            ->assertHasErrors(['complaintReason' => 'required']);

        $this->assertSame(VendorProcessingStatus::DIPROSES, $vendorOrder->refresh()->status);
        $this->assertSame(0, AuditEvent::query()->where('action', MarketplaceAuditActions::ORDER_STATUS_CHANGED)->count());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function ineligibleStatusProvider(): iterable
    {
        yield 'awaiting vendor response' => [VendorProcessingStatus::MENUNGGU_VENDOR];
        yield 'rejected by vendor' => [VendorProcessingStatus::DITOLAK_VENDOR];
        yield 'already complete' => [VendorProcessingStatus::SELESAI];
        yield 'already a complaint' => [VendorProcessingStatus::KOMPLAIN];
        yield 'cancelled' => [VendorProcessingStatus::DIBATALKAN];
    }

    #[DataProvider('ineligibleStatusProvider')]
    public function test_an_ineligible_order_status_refuses_the_complaint(string $status): void
    {
        $order = $this->order('cust-1', $status, PaymentState::BELUM_DIBAYAR);
        $vendorOrder = $order->vendorOrders->first();

        // The action button itself must not render for an ineligible status.
        Livewire::test(OrderTracking::class, ['orderNumber' => $order->order_number, 'customerRef' => 'cust-1'])
            ->assertDontSee('Ajukan komplain')
            // A direct call (bypassing the hidden UI) must still be refused
            // server-side — the real guard is not the `@if` in the view.
            ->set('complaintReason', 'Ada masalah dengan pesanan ini, mohon ditinjau kembali.')
            ->call('fileComplaint')
            ->assertHasNoErrors();

        $this->assertSame($status, $vendorOrder->refresh()->status);
        $this->assertSame(0, AuditEvent::query()->where('action', MarketplaceAuditActions::ORDER_STATUS_CHANGED)->count());
    }

    /**
     * PUB-024 previously rendered only the bundled `$order->total()` — the
     * per-line `MarketplaceOrderItem` snapshot (`items()`, a real `hasMany`
     * with `product_id`/`quantity`/`unit_price_minor`/`line_total_minor`)
     * was never loaded or shown. This proves the itemized breakdown now
     * renders with the correct product name and amounts, alongside the
     * pre-existing subtotal/delivery-fee/total figures.
     */
    public function test_order_items_render_with_the_correct_product_name_and_amounts(): void
    {
        $order = $this->order('cust-1', VendorProcessingStatus::DIPROSES, PaymentState::BELUM_DIBAYAR);
        $listing = VendorListing::query()->where('vendor_id', $order->vendor_id)->sole();

        $order->items()->create([
            'vendor_listing_id' => $listing->id,
            'product_id' => $listing->product_id,
            'quantity' => 2,
            'unit_price_minor' => 150_000,
            'line_total_minor' => 300_000,
            'price_version' => 1,
        ]);

        $productName = Product::findByCode(ProductCode::FLOWER_BOARD)->name;

        Livewire::test(OrderTracking::class, ['orderNumber' => $order->order_number, 'customerRef' => 'cust-1'])
            ->assertOk()
            ->assertSee($productName)
            // `Money`'s minor-units factor is 100 (config('money.minor_units')),
            // so unit_price_minor 150_000 / line_total_minor 300_000 /
            // delivery_fee_minor 25_000 / total_minor 325_000 format as
            // Rp 1.500 / Rp 3.000 / Rp 250 / Rp 3.250 — none fabricated,
            // all read straight from the order/item rows the fixture wrote.
            ->assertSeeInOrder(['Rp 1.500', 'Rp 3.000'])
            ->assertSee('Rp 250')
            ->assertSee('Rp 3.250');
    }

    public function test_a_customer_cannot_file_a_complaint_on_someone_elses_order(): void
    {
        $order = $this->order('cust-owner', VendorProcessingStatus::DITERIMA_VENDOR, PaymentState::BELUM_DIBAYAR);
        $vendorOrder = $order->vendorOrders->first();

        Livewire::test(OrderTracking::class, [
            'orderNumber' => $order->order_number, 'customerRef' => 'cust-intruder',
        ])
            ->assertDontSee('Ajukan komplain')
            ->set('complaintReason', 'Mencoba mengajukan komplain atas pesanan orang lain.')
            ->call('fileComplaint')
            ->assertHasNoErrors();

        $this->assertSame(VendorProcessingStatus::DITERIMA_VENDOR, $vendorOrder->refresh()->status);
        $this->assertSame(0, AuditEvent::query()->where('action', MarketplaceAuditActions::ORDER_STATUS_CHANGED)->count());
        $this->assertNull(VendorOrder::query()->where('id', $vendorOrder->id)->where('status', VendorProcessingStatus::KOMPLAIN)->first());
    }
}
