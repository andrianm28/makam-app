<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Public\Marketplace;

use App\Domain\Marketplace\Actions\AddToCart;
use App\Domain\Marketplace\AvailabilityMode;
use App\Domain\Marketplace\EvidenceRequirement;
use App\Domain\Marketplace\Models\Cart as CartModel;
use App\Domain\Marketplace\Models\Product;
use App\Domain\Marketplace\Models\ServiceArea;
use App\Domain\Marketplace\Models\Vendor;
use App\Domain\Marketplace\Models\VendorListing;
use App\Domain\Marketplace\ProductCode;
use App\Livewire\Public\Marketplace\Checkout;
use App\Platform\FeatureGate\Contracts\GateRegistrySource;
use App\Platform\FeatureGate\FeatureGateResolver;
use App\Platform\FeatureGate\GateRegistrySnapshot;
use App\Platform\FeatureGate\GateState;
use App\Platform\FeatureGate\ModeResolver;
use App\Platform\FeatureGate\Modes\PaymentMode;
use App\Platform\Payment\Models\PaymentSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Task 6's marketplace checkout online branch.
 *
 * The online option renders when `G-PAY-01` is open, but the marketplace
 * session path is DEFERRED: `OpenPaymentSession` refuses a Marketplace
 * `OrderType` with `PaymentSessionOrderTypeNotSupportedException` until the
 * marketplace precondition lane lands (no `Quote`, no
 * `AuthorizeOrderPaymentOpening` analog — see `OrderType`'s doc block). The
 * checkout therefore surfaces that refusal as an honest fail-closed state —
 * never a 500, never a fabricated session — and keeps the manual path as the
 * live submit. The day the deferred service lands, `payOnline()` starts
 * working with no code change here.
 */
final class CheckoutOnlinePaymentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['marketplace.badan_usaha_ref' => 'badan-usaha-test']);
    }

    private function withPaymentGate(bool $open): void
    {
        $source = new class($open) implements GateRegistrySource
        {
            public function __construct(private readonly bool $open) {}

            public function load(): GateRegistrySnapshot
            {
                return new GateRegistrySnapshot([
                    'G-PAY-01' => GateState::fromRecord('G-PAY-01', open: $this->open),
                ]);
            }
        };

        $this->app->instance(ModeResolver::class, new ModeResolver(new FeatureGateResolver($source)));

        $this->assertSame(
            $open ? PaymentMode::Online : PaymentMode::ManualCoordination,
            app(ModeResolver::class)->paymentMode(),
            'The fixture gate must resolve as requested or these tests prove nothing.',
        );
    }

    private function seedCart(): ServiceArea
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

        return $area;
    }

    private function placeOrder($component, ServiceArea $area)
    {
        return $component
            ->set('recipientName', 'Budi Santoso')
            ->set('recipientPhone', '081234567890')
            ->set('recipientEmail', 'budi@example.test')
            ->set('selectedAreaCode', $area->area_code)
            ->call('placeOrder')
            ->assertHasNoErrors();
    }

    public function test_the_online_option_renders_when_the_gate_is_open(): void
    {
        $this->withPaymentGate(open: true);
        $area = $this->seedCart();

        $component = Livewire::test(Checkout::class);
        $this->placeOrder($component, $area);

        $component
            ->assertSee('Pembayaran Online')
            ->assertSee('Bayar Online')
            ->assertDontSee('Pembayaran online belum tersedia');
    }

    public function test_online_submit_surfaces_the_marketplace_deferral_honestly(): void
    {
        $this->withPaymentGate(open: true);
        Http::fake();
        $area = $this->seedCart();

        $component = Livewire::test(Checkout::class);
        $this->placeOrder($component, $area);

        $component
            ->call('payOnline')
            ->assertSet('onlinePaymentUnavailable', true)
            ->assertSee('belum tersedia untuk pesanan marketplace');

        // Fail-closed: no session was fabricated and no provider was called.
        $this->assertSame(0, PaymentSession::query()->count());
        Http::assertNothingSent();
    }

    public function test_online_submit_before_placing_an_order_does_nothing(): void
    {
        $this->withPaymentGate(open: true);
        Http::fake();
        $this->seedCart();

        Livewire::test(Checkout::class)
            ->call('payOnline')
            ->assertSet('onlinePaymentUnavailable', false);

        $this->assertSame(0, PaymentSession::query()->count());
        Http::assertNothingSent();
    }

    public function test_the_online_option_is_absent_when_the_gate_is_closed(): void
    {
        $this->withPaymentGate(open: false);
        $this->seedCart();

        Livewire::test(Checkout::class)
            ->assertSee('Pembayaran online belum tersedia')
            ->assertDontSee('Bayar Online');
    }
}
