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
use App\Domain\Marketplace\ProductCode;
use App\Livewire\Public\Marketplace\Checkout;
use App\Platform\Payment\Models\PaymentVerification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * MKT-01 — `Checkout`'s IDOR-shaped properties.
 *
 * `$idempotencyKey`, `$onlinePaymentAllowed`, `$orderPlaced`, and
 * `$placedOrderNumber` are all written exactly once by server-side logic
 * (`mount()`/`placeOrder()`) and never legitimately updated from a client
 * payload. Before this fix none carried `#[Locked]`: a forged
 * `/livewire/update` request setting `orderPlaced = true` and
 * `placedOrderNumber` to a STRANGER's real order number would have let
 * `submitManualProof()`/`payOnline()` act against that stranger's order —
 * `Livewire::test(...)->set(...)` exercises the exact same
 * `HandleComponents::updateProperties()` synthesizer path a real forged
 * `/livewire/update` HTTP request goes through, so this is a genuine proof
 * of the guard, not a unit test calling PHP property assignment directly.
 */
final class AuthzCheckoutProbeTest extends TestCase
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

    public function test_idempotency_key_cannot_be_set_from_the_client(): void
    {
        $this->seedCart();

        $this->expectException(CannotUpdateLockedPropertyException::class);

        Livewire::test(Checkout::class)->set('idempotencyKey', 'attacker-supplied-key');
    }

    public function test_online_payment_allowed_cannot_be_set_from_the_client(): void
    {
        $this->seedCart();

        $this->expectException(CannotUpdateLockedPropertyException::class);

        Livewire::test(Checkout::class)->set('onlinePaymentAllowed', true);
    }

    public function test_order_placed_cannot_be_set_from_the_client(): void
    {
        $this->seedCart();

        $this->expectException(CannotUpdateLockedPropertyException::class);

        Livewire::test(Checkout::class)->set('orderPlaced', true);
    }

    public function test_placed_order_number_cannot_be_set_from_the_client(): void
    {
        $this->seedCart();

        $this->expectException(CannotUpdateLockedPropertyException::class);

        Livewire::test(Checkout::class)->set('placedOrderNumber', 'MKT-VICTIM-ORDER');
    }

    /**
     * The second, independent layer: even setting up a component instance
     * that BELIEVES it already placed a stranger's order (bypassing the
     * `#[Locked]` client-update guard entirely, the way a future refactor
     * that removed the attribute would) still cannot submit manual payment
     * proof against that order, because `submitManualProof()` re-derives
     * the acting customer's identity fresh and re-confirms ownership via
     * `MarketplaceOrderQuery::findForCustomer()` before doing anything.
     */
    public function test_submit_manual_proof_refuses_an_order_that_does_not_belong_to_the_current_session(): void
    {
        [$victimCart, $victimArea] = $this->seedCart();

        $victimComponent = Livewire::test(Checkout::class)
            ->set('recipientName', 'Korban')
            ->set('recipientPhone', '081200000000')
            ->set('recipientEmail', 'korban@example.test')
            ->set('selectedAreaCode', $victimArea->area_code)
            ->call('placeOrder')
            ->assertHasNoErrors();

        $victimOrder = MarketplaceOrder::firstOrFail();
        $this->assertNotNull($victimComponent->get('placedOrderNumber'));

        // A different session (the attacker) builds its OWN component
        // instance and — simulating a bypass of the `#[Locked]` guard —
        // is seeded at construction with the victim's real order number
        // and `orderPlaced = true`, exactly the state a successful forged
        // update would have produced.
        session()->flush();
        session()->regenerate();

        $attackerComponent = Livewire::test(Checkout::class, [
            'orderPlaced' => true,
            'placedOrderNumber' => $victimOrder->order_number,
        ]);

        $attackerComponent
            ->set('manualPaymentReference', 'ATTACKER-REF')
            ->set('manualPaymentAmount', '325000')
            ->call('submitManualProof');

        // Nothing was written against the victim's order.
        $this->assertSame(
            0,
            PaymentVerification::query()->where('reference', $victimOrder->order_number)->count()
        );
    }
}
