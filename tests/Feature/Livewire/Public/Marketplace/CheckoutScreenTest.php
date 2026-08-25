<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Public\Marketplace;

use App\Domain\Marketplace\Actions\AddToCart;
use App\Domain\Marketplace\AvailabilityMode;
use App\Domain\Marketplace\EvidenceRequirement;
use App\Domain\Marketplace\Models\Cart as CartModel;
use App\Domain\Marketplace\Models\MarketplaceOrder;
use App\Domain\Marketplace\Models\Product;
use App\Domain\Marketplace\Models\ServiceArea;
use App\Domain\Marketplace\Models\Vendor;
use App\Domain\Marketplace\Models\VendorListing;
use App\Domain\Marketplace\PaymentState;
use App\Domain\Marketplace\ProductCode;
use App\Livewire\Public\Marketplace\Checkout;
use App\Platform\Payment\Models\PaymentVerification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

final class CheckoutScreenTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['marketplace.badan_usaha_ref' => 'badan-usaha-test']);
    }

    private function seedCart(): array
    {
        $vendor = Vendor::create(['name' => 'Toko Bunga', 'is_active' => true]);
        $listing = VendorListing::create([
            'vendor_id' => $vendor->id,
            'product_id' => Product::findByCode(ProductCode::FLOWER_BOARD)->id,
            'price_minor' => 150_000, 'price_version' => 1,
            'availability_mode' => AvailabilityMode::STOCKED, 'stock_quantity' => 10,
            'evidence_requirement' => EvidenceRequirement::NONE, 'is_active' => true,
        ]);
        $area = ServiceArea::create([
            'vendor_id' => $vendor->id, 'area_code' => 'JKT-SELATAN',
            'area_label' => 'Jakarta Selatan', 'delivery_fee_minor' => 25_000, 'is_active' => true,
        ]);
        $cart = CartModel::create(['session_ref' => session()->getId()]);
        (new AddToCart)->handle($cart, $listing, 2);

        return [$cart->fresh(), $area];
    }

    private function fillRecipient($component)
    {
        return $component
            ->set('recipientName', 'Budi Santoso')
            ->set('recipientPhone', '081234567890')
            ->set('recipientEmail', 'budi@example.test');
    }

    public function test_the_online_payment_option_is_shown_as_gate_closed_not_hidden(): void
    {
        $this->seedCart();

        Livewire::test(Checkout::class)
            ->assertOk()
            // §6.9: the gate is disclosed with a reason and a live alternative.
            ->assertSee('Pembayaran online belum tersedia')
            ->assertSee('transfer manual');
    }

    public function test_the_screen_states_the_single_vendor_constraint(): void
    {
        $this->seedCart();

        Livewire::test(Checkout::class)->assertSee('satu vendor');
    }

    public function test_placing_an_order_creates_it_unpaid_and_awaiting_the_vendor(): void
    {
        [, $area] = $this->seedCart();

        Livewire::test(Checkout::class)
            ->set('recipientName', 'Budi Santoso')
            ->set('recipientPhone', '081234567890')
            ->set('recipientEmail', 'budi@example.test')
            ->set('selectedAreaCode', $area->area_code)
            ->call('placeOrder')
            ->assertHasNoErrors();

        $order = MarketplaceOrder::firstOrFail();
        $this->assertSame(PaymentState::BELUM_DIBAYAR, $order->payment_state);
        $this->assertSame(1, $order->vendorOrders()->count());
        $this->assertSame('budi@example.test', $order->vendorOrders()->first()->customer_email);
    }

    public function test_a_double_submit_creates_only_one_order(): void
    {
        [, $area] = $this->seedCart();

        $component = Livewire::test(Checkout::class)
            ->set('recipientName', 'Budi Santoso')
            ->set('recipientPhone', '081234567890')
            ->set('recipientEmail', 'budi@example.test')
            ->set('selectedAreaCode', $area->area_code);
        $component->call('placeOrder');
        $component->call('placeOrder');

        $this->assertDatabaseCount('marketplace_orders', 1);
    }

    public function test_a_missing_service_area_shows_an_inline_error_and_keeps_entered_data(): void
    {
        $this->seedCart();

        Livewire::test(Checkout::class)
            ->set('recipientName', 'Budi Santoso')
            ->set('selectedAreaCode', '')
            ->call('placeOrder')
            ->assertHasErrors(['selectedAreaCode'])
            // §6.3: never clear what the customer typed.
            ->assertSet('recipientName', 'Budi Santoso');

        $this->assertDatabaseCount('marketplace_orders', 0);
    }

    public function test_an_unconfigured_badan_usaha_degrades_without_leaking_internals(): void
    {
        config(['marketplace.badan_usaha_ref' => '']);
        [, $area] = $this->seedCart();

        Livewire::test(Checkout::class)
            ->set('recipientName', 'Budi Santoso')
            ->set('recipientPhone', '081234567890')
            ->set('recipientEmail', 'budi@example.test')
            ->set('selectedAreaCode', $area->area_code)
            ->call('placeOrder')
            ->assertOk()
            ->assertSee('Checkout belum dapat diproses')
            ->assertDontSee('badan_usaha_ref');

        $this->assertDatabaseCount('marketplace_orders', 0);
    }

    public function test_submitting_manual_proof_creates_a_payment_verification_for_the_order(): void
    {
        [, $area] = $this->seedCart();

        $component = Livewire::test(Checkout::class)
            ->set('recipientName', 'Budi Santoso')
            ->set('recipientPhone', '081234567890')
            ->set('recipientEmail', 'budi@example.test')
            ->set('selectedAreaCode', $area->area_code)
            ->call('placeOrder')
            ->assertHasNoErrors();

        $order = MarketplaceOrder::firstOrFail();

        $component->set('manualPaymentReference', 'TRF-12345')
            ->call('submitManualProof')
            ->assertHasNoErrors();

        $verification = PaymentVerification::query()
            ->where('reference', $order->order_number)
            ->sole();
        $this->assertSame('TRF-12345', $verification->payment_reference);
        $this->assertSame('MANUAL', $verification->payment_method);
    }
}
