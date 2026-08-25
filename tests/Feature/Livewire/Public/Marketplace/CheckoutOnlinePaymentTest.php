<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Public\Marketplace;

use App\Domain\Marketplace\Actions\AddToCart;
use App\Domain\Marketplace\Actions\MarkMarketplaceOrderPaid;
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
use App\Platform\FeatureGate\Contracts\GateRegistrySource;
use App\Platform\FeatureGate\FeatureGateResolver;
use App\Platform\FeatureGate\GateRegistrySnapshot;
use App\Platform\FeatureGate\GateState;
use App\Platform\FeatureGate\ModeResolver;
use App\Platform\FeatureGate\Modes\PaymentMode;
use App\Platform\Payment\Models\PaymentIntent;
use App\Platform\Payment\Models\PaymentSession;
use App\Platform\Payment\PaymentIntentDecision;
use App\Platform\Payment\PaymentProviders;
use App\Platform\Payment\SessionState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The marketplace checkout's online branch, since the follow-up service
 * landed: `OpenPaymentSession` now opens a real session for a Marketplace
 * `OrderType` through `App\Domain\Marketplace\Actions\
 * GuardMarketplacePaymentOpening`, and `payOnline()` redirects to the hosted
 * checkout link — mirrors `BookingWizardOnlinePaymentTest`'s style.
 *
 * Unlike booking, a freshly placed marketplace order needs no operator-side
 * act before it is payable: `GuardMarketplacePaymentOpening` has no
 * admin-authorization condition (see that guard's own class doc block for
 * why), so the FIRST click after `placeOrder()` can open a session — there
 * is no `operatorCompletes()` step here.
 */
final class CheckoutOnlinePaymentTest extends TestCase
{
    use RefreshDatabase;

    private const string MERCHANT_REF = 'mk-merchant-dev';

    private const string BADAN_USAHA_REF = 'badan-usaha-dev';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'marketplace.badan_usaha_ref' => 'badan-usaha-test',
            'payment.merchant_ref' => self::MERCHANT_REF,
            'payment.badan_usaha_ref' => self::BADAN_USAHA_REF,
            'payment.providers.'.PaymentProviders::SUMOPOD_SANDBOX.'.api_key' => 'test-key',
        ]);
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

    private function seedCart(int $priceMinor = 150_000): ServiceArea
    {
        $vendor = Vendor::create(['name' => 'Toko Bunga', 'is_active' => true]);
        $listing = VendorListing::create([
            'vendor_id' => $vendor->id,
            'product_id' => Product::findByCode(ProductCode::FLOWER_BOARD)->id,
            'price_minor' => $priceMinor, 'price_version' => 1,
            'availability_mode' => AvailabilityMode::STOCKED, 'stock_quantity' => 10,
            'evidence_requirement' => EvidenceRequirement::NONE, 'is_active' => true,
        ]);
        $area = ServiceArea::create([
            'vendor_id' => $vendor->id, 'area_code' => 'JKT-SELATAN',
            'area_label' => 'Jakarta Selatan', 'delivery_fee_minor' => 25_000, 'is_active' => true,
        ]);
        $cart = CartModel::create(['session_ref' => session()->getId()]);
        (new AddToCart)->handle($cart, $listing, 1);

        return $area;
    }

    private function placeOrder(Testable $component, ServiceArea $area): Testable
    {
        return $component
            ->set('recipientName', 'Budi Santoso')
            ->set('recipientPhone', '081234567890')
            ->set('recipientEmail', 'budi@example.test')
            ->set('selectedAreaCode', $area->area_code)
            ->call('placeOrder')
            ->assertHasNoErrors();
    }

    private function fakeProviderSuccess(): void
    {
        Http::fake([
            'api-pay-sandbox.sumopod.com/api/v1/payments' => Http::response([
                'payment_id' => 'uuid-mkt-1',
                'order_id' => 'MKT-ORD-1',
                // Whole rupiah — the major-unit form of the order total
                // (150_000 subtotal + 25_000 delivery = 175_000 minor units).
                'amount' => 1_750,
                'fee' => 12,
                'net_amount' => 1_738,
                'payment_link_url' => 'https://checkout.sumopod.com/mkt',
                'status' => 'pending',
            ], 201),
        ]);
    }

    public function test_the_online_option_renders_when_the_gate_is_open(): void
    {
        $this->withPaymentGate(open: true);
        $area = $this->seedCart();

        $component = Livewire::test(Checkout::class);
        $this->placeOrder($component, $area);

        $component
            ->assertSee('Pembayaran Online')
            ->assertSee('Bayar Online');
    }

    /**
     * ADR-0035 item 1's mitigation, mirrored from the booking wizard's own
     * warning. `setUp()` never overrides `payment.default`, so it stays on
     * `PaymentProviders::SUMOPOD_SANDBOX`.
     */
    public function test_the_sandbox_warning_shows_before_the_pay_button(): void
    {
        $this->withPaymentGate(open: true);
        $area = $this->seedCart();

        $component = Livewire::test(Checkout::class);
        $this->placeOrder($component, $area);

        $component
            ->assertSeeInOrder(['ANDA TIDAK AKAN MENGIRIM UANG SUNGGUHAN', 'Bayar Online'])
            ->assertSee('simulasi (sandbox)');
    }

    public function test_submitting_online_opens_a_session_and_redirects_to_the_hosted_checkout(): void
    {
        $this->withPaymentGate(open: true);
        $this->fakeProviderSuccess();
        $area = $this->seedCart();

        $component = Livewire::test(Checkout::class);
        $this->placeOrder($component, $area);

        $component->call('payOnline')
            ->assertRedirect('https://checkout.sumopod.com/mkt');

        $order = MarketplaceOrder::query()->sole();
        $session = PaymentSession::query()->sole();

        $this->assertSame(SessionState::AwaitingPayment->value, $session->state);
        $this->assertSame($order->total()->toMinorInt(), $session->amount_minor);
        $this->assertSame(self::MERCHANT_REF, $session->merchant_ref);
        $this->assertSame('https://checkout.sumopod.com/mkt', $session->payment_link_url);

        $this->assertSame(
            PaymentIntentDecision::Allowed->value,
            PaymentIntent::query()->whereKey($session->payment_intent_id)->sole()->decision,
        );

        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/api/v1/payments'));
    }

    public function test_online_submit_before_placing_an_order_does_nothing(): void
    {
        $this->withPaymentGate(open: true);
        Http::fake();
        $this->seedCart();

        Livewire::test(Checkout::class)
            ->call('payOnline')
            ->assertSet('onlinePaymentError', null);

        $this->assertSame(0, PaymentSession::query()->count());
        Http::assertNothingSent();
    }

    /**
     * Re-clicking must not open a second session and must not call the
     * provider twice — the stored `session_id`/`link_url` is re-pointed at
     * instead of re-opened.
     */
    public function test_reclicking_bayar_online_is_idempotent(): void
    {
        $this->withPaymentGate(open: true);
        $this->fakeProviderSuccess();
        $area = $this->seedCart();

        $component = Livewire::test(Checkout::class);
        $this->placeOrder($component, $area);

        $component->call('payOnline')->assertRedirect('https://checkout.sumopod.com/mkt');
        $component->call('payOnline')->assertRedirect('https://checkout.sumopod.com/mkt');

        $this->assertSame(1, PaymentSession::query()->count());
        Http::assertSentCount(1);
    }

    public function test_online_submit_on_an_already_paid_order_is_refused_without_opening_a_second_session(): void
    {
        $this->withPaymentGate(open: true);
        Http::fake();
        $area = $this->seedCart();

        $component = Livewire::test(Checkout::class);
        $this->placeOrder($component, $area);

        $order = MarketplaceOrder::query()->sole();
        app(MarkMarketplaceOrderPaid::class)($order, $order->total()->toMinorInt());

        $component->call('payOnline')
            ->assertSet('onlinePaymentError', 'Pesanan ini telah dibayar dan tidak perlu dibayar lagi.');

        $this->assertSame(0, PaymentSession::query()->count());
        Http::assertNothingSent();
    }

    public function test_a_provider_failure_fails_closed_and_keeps_the_manual_path(): void
    {
        $this->withPaymentGate(open: true);
        Http::fake([
            'api-pay-sandbox.sumopod.com/api/v1/payments' => Http::response(['error' => 'bad'], 400),
        ]);
        $area = $this->seedCart();

        $component = Livewire::test(Checkout::class);
        $this->placeOrder($component, $area);

        $component->call('payOnline')
            ->assertSet(
                'onlinePaymentError',
                'Layanan pembayaran online sedang tidak tersedia. Silakan coba lagi atau gunakan transfer manual.',
            )
            ->assertSee('Pembayaran transfer manual');

        $this->assertSame(0, PaymentSession::query()->count());
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
