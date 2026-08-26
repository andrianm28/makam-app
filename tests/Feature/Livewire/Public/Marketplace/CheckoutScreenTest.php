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
use App\Models\User;
use App\Platform\Payment\Models\PaymentVerification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

    /**
     * Same fixture as `seedCart()`, but the cart is owned by an authenticated
     * user (`customer_ref`) rather than a guest session — the exact shape
     * `Checkout::cart()` resolves for a signed-in customer.
     */
    private function seedCartForUser(User $user): array
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
        $cart = CartModel::create(['customer_ref' => (string) $user->id]);
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

    /**
     * The real production-breaking bug this test exists to catch: before the
     * fix, `PlaceMarketplaceOrder::assessPayable()` resolved the ambient
     * per-request `ActorContext` — which, for ANY logged-in customer, is the
     * real authenticated actor, not a guest one.
     * `FinanceVendorPayableAuthorizer::authorizeUnattended()` then threw
     * `VendorPayableNotAuthorisedException` for every such checkout,
     * silently swallowed by `Checkout::placeOrder()`'s broad
     * `catch (Throwable $e)` into a generic "Checkout belum dapat diproses"
     * error — i.e. checkout was broken for every authenticated customer.
     * This asserts the full HTTP/Livewire-component-level path — not just a
     * unit test of `assessPayable()` in isolation — because that broad catch
     * is exactly the layer that hid the defect before.
     */
    public function test_an_authenticated_customer_can_place_an_order_end_to_end(): void
    {
        $user = User::factory()->create();
        [, $area] = $this->seedCartForUser($user);

        $this->actingAs($user);

        Livewire::test(Checkout::class)
            ->set('recipientName', 'Budi Santoso')
            ->set('recipientPhone', '081234567890')
            ->set('recipientEmail', 'budi@example.test')
            ->set('selectedAreaCode', $area->area_code)
            ->call('placeOrder')
            ->assertHasNoErrors()
            ->assertDontSee('Checkout belum dapat diproses');

        $order = MarketplaceOrder::firstOrFail();
        $this->assertSame(PaymentState::BELUM_DIBAYAR, $order->payment_state);
        $this->assertSame((string) $user->id, $order->customer_ref);

        // The payable was really opened, and attributed to the system actor
        // (never the authenticated customer) — proves the fix's actual
        // effect, not just the absence of an exception.
        $payable = DB::table('vendor_payables')
            ->where('source_type', 'marketplace_order')
            ->where('source_id', $order->id)
            ->sole();
        $this->assertSame(300_000, (int) $payable->amount_minor);
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
            ->set('manualPaymentAmount', '325000')
            ->call('submitManualProof')
            ->assertHasNoErrors();

        $verification = PaymentVerification::query()
            ->where('reference', $order->order_number)
            ->sole();
        $this->assertSame('TRF-12345', $verification->payment_reference);
        $this->assertSame('MANUAL', $verification->payment_method);
        $this->assertSame($order->id, $verification->order_id);
        $this->assertSame(325_000_00, $verification->amount_minor);
        $this->assertSame('IDR', $verification->currency);
    }

    public function test_submitting_manual_proof_without_an_amount_is_rejected(): void
    {
        [, $area] = $this->seedCart();

        $component = Livewire::test(Checkout::class)
            ->set('recipientName', 'Budi Santoso')
            ->set('recipientPhone', '081234567890')
            ->set('recipientEmail', 'budi@example.test')
            ->set('selectedAreaCode', $area->area_code)
            ->call('placeOrder')
            ->assertHasNoErrors();

        $component->set('manualPaymentReference', 'TRF-12345')
            ->set('manualPaymentAmount', '')
            ->call('submitManualProof')
            ->assertHasErrors(['manualPaymentAmount']);

        $this->assertSame(0, PaymentVerification::query()->count());
    }
}
