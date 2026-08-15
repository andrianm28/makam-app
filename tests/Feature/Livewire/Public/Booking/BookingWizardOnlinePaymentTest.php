<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Public\Booking;

use App\Domain\Booking\Actions\StartBookingDraft;
use App\Domain\Booking\BookingPaymentMethod;
use App\Domain\Booking\BookingWizardStep;
use App\Domain\CemeteryDirectory\LaunchCityCode;
use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Domain\OrderWorkflow\Models\Order;
use App\Domain\OrderWorkflow\OrderStatus;
use App\Domain\OrderWorkflow\ProductType;
use App\Domain\Quotation\Models\Quote;
use App\Domain\Quotation\QuoteStatus;
use App\Livewire\Public\Booking\BookingWizard;
use App\Models\User;
use App\Platform\FeatureGate\Contracts\GateRegistrySource;
use App\Platform\FeatureGate\FeatureGateResolver;
use App\Platform\FeatureGate\GateRegistrySnapshot;
use App\Platform\FeatureGate\GateState;
use App\Platform\FeatureGate\ModeResolver;
use App\Platform\FeatureGate\Modes\PaymentMode;
use App\Platform\IdentityAccess\ActorContextResolver;
use App\Platform\IdentityAccess\Roles\Actions\GrantActorRole;
use App\Platform\IdentityAccess\Roles\ActorRole;
use App\Platform\IdentityAccess\Scopes\Models\ScopeAssignment;
use App\Platform\IdentityAccess\Scopes\ScopeEntityType;
use App\Platform\Payment\Actions\OpenPaymentSession;
use App\Platform\Payment\Actions\OpenPaymentSessionCommand;
use App\Platform\Payment\Models\PaymentIntent;
use App\Platform\Payment\Models\PaymentSession;
use App\Platform\Payment\OrderType;
use App\Platform\Payment\PaymentIntentDecision;
use App\Platform\Payment\PaymentProviders;
use App\Platform\Payment\SessionState;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Task 6's booking Step 8 online branch: when `G-PAY-01` is open (dev), the
 * Step 8 online option calls `OpenPaymentSession` with the draft's order and
 * the accepted-quote total, then redirects the customer to the hosted
 * checkout. The gate-closed behaviour is untouched (the manual fallback is
 * covered by the existing suites, which must stay green).
 *
 * The happy path needs every one of the six guard conditions satisfied —
 * confirmed order, accepted unexpired quote, authorized opener with an
 * ORDER-scope grant, matching amount, bound merchant, open gate — exactly as
 * `OpenPaymentSessionTest` arranges them. A real customer at Step 8 will
 * usually NOT hold those yet (the operator-side quote/confirmation journey
 * happens off-screen); those journeys surface the honest fail-closed states
 * asserted below.
 */
final class BookingWizardOnlinePaymentTest extends TestCase
{
    use RefreshDatabase;

    private const string MERCHANT_REF = 'mk-merchant-dev';

    private const string BADAN_USAHA_REF = 'badan-usaha-dev';

    private const int QUOTE_TOTAL_MINOR = 1_500_000_00;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'payment.merchant_ref' => self::MERCHANT_REF,
            'payment.badan_usaha_ref' => self::BADAN_USAHA_REF,
            'payment.providers.'.PaymentProviders::SUMOPOD_SANDBOX.'.api_key' => 'test-key',
        ]);
    }

    /**
     * Drive the component's own save methods through Steps 1-7 so the draft
     * is bound to this test session exactly as a real journey's is, ending on
     * Step 8 (PAYMENT).
     */
    private function journeyToStepEight(): Testable
    {
        $cemetery = Cemetery::query()
            ->where('city', LaunchCityCode::JAKARTA)
            ->where('publication_status', 'published')
            ->whereDoesntHave('packages')
            ->firstOrFail();

        return Livewire::test(BookingWizard::class)
            ->call('saveStep1', LaunchCityCode::JAKARTA)
            ->call('saveStep2', $cemetery->id)
            ->call('saveStep3', 'NEW_GRAVE')
            ->call('saveStep4', [
                ['code' => 'DOCUMENT_PROCESSING', 'quantity' => 1],
                ['code' => 'GRAVE_DIGGING', 'quantity' => 1],
            ])
            ->call('goToStep', BookingWizardStep::CUSTOMER_DATA)
            ->set('customerFullName', 'Test User')
            ->set('customerMobile', '081234567890')
            ->set('customerEmail', 'test@example.com')
            ->set('customerAddress', 'Jl. Contoh No. 1')
            ->set('customerRelationship', 'PASANGAN')
            ->set('customerContactChannel', 'WHATSAPP')
            ->set('privacyNoticeAccepted', true)
            ->call('saveStep6')
            ->set('deceasedFullName', 'Almarhum Test')
            ->set('deceasedDateOfBirth', '1980-05-10')
            ->set('deceasedDateOfDeath', '2026-08-01')
            ->set('deceasedRelationship', 'PASANGAN')
            ->set('deceasedGender', 'LAKI_LAKI')
            ->call('saveStep7')
            ->assertSet('currentStep', BookingWizardStep::PAYMENT);
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

    private function fakeProviderSuccess(): void
    {
        Http::fake([
            'api-pay-sandbox.sumopod.com/api/v1/payments' => Http::response([
                'payment_id' => 'uuid-1',
                'order_id' => 'MK-ORD-1',
                // The provider's wire unit is whole rupiah — the same
                // Rp 1.500.000 the webhook envelope will later carry.
                'amount' => 1_500_000,
                'fee' => 38_800,
                'net_amount' => 1_461_200,
                'payment_link_url' => 'https://checkout.sumopod.com/x',
                'status' => 'pending',
            ], 201),
        ]);
    }

    /**
     * A booking order linked to the draft, in a confirmed status with an
     * accepted unexpired quote and an authorized opener — the six-condition
     * guard's preconditions, mirroring `OpenPaymentSessionTest`'s fixture.
     */
    private function satisfiedOrderFor(string $draftId, OrderStatus $status = OrderStatus::PENAWARAN_TERKIRIM): Order
    {
        $order = Order::query()->create([
            'reference' => 'MK-ORD-1',
            'product_type' => ProductType::AT_NEED_SERVICE_ORDER->value,
            'status' => $status->value,
            'booking_draft_id' => $draftId,
        ]);

        $quote = Quote::query()->create([
            'order_id' => $order->getKey(),
            'version_number' => 1,
            'status' => QuoteStatus::ISSUED->value,
            'total_minor' => self::QUOTE_TOTAL_MINOR,
            'currency' => 'IDR',
            'issued_at' => CarbonImmutable::now(),
            'expires_at' => CarbonImmutable::now()->addDays(7),
            'issued_by_ref' => 'actor:admin-1',
            'issued_by_role' => 'admin',
        ]);

        $quote->accept(CarbonImmutable::now(), 'actor:admin-1');

        $user = User::factory()->create();

        app(GrantActorRole::class)($user->id, ActorRole::ADMIN, 'test', 1);

        $this->actingAs($user);
        $this->app->forgetInstance(ActorContextResolver::class);

        ScopeAssignment::query()->create([
            'actor_identifier' => (string) $user->id,
            'entity_type' => ScopeEntityType::ORDER,
            'entity_id' => $order->getKey(),
        ]);

        return $order;
    }

    /**
     * Open a session through the real action (not the wizard) — used by the
     * return-page tests, which only need the session to exist.
     */
    private function openSession(): PaymentSession
    {
        return app(OpenPaymentSession::class)(new OpenPaymentSessionCommand(
            orderType: OrderType::Booking,
            orderRef: 'MK-ORD-1',
            amountMinor: self::QUOTE_TOTAL_MINOR,
            merchantRef: self::MERCHANT_REF,
            successReturnUrl: 'https://makam.test/pembayaran/kembali',
            cancelReturnUrl: 'https://makam.test/pembayaran/batal',
        ));
    }

    public function test_the_online_option_renders_when_the_gate_is_open(): void
    {
        $this->withPaymentGate(open: true);

        $this->journeyToStepEight()
            ->assertSee('Pembayaran Online')
            ->assertSee('Bayar Sekarang');
    }

    public function test_submitting_online_opens_a_session_and_redirects_to_the_hosted_checkout(): void
    {
        $this->withPaymentGate(open: true);
        $this->fakeProviderSuccess();

        $draftId = $this->journeyToStepEight()->get('draftId');
        $this->satisfiedOrderFor($draftId);

        Livewire::test(BookingWizard::class, ['draftId' => $draftId])
            ->call('openOnlinePayment')
            ->assertRedirect('https://checkout.sumopod.com/x');

        $session = PaymentSession::query()->sole();

        $this->assertSame(SessionState::AwaitingPayment->value, $session->state);
        $this->assertSame(self::QUOTE_TOTAL_MINOR, $session->amount_minor);
        $this->assertSame(self::MERCHANT_REF, $session->merchant_ref);
        $this->assertSame('https://checkout.sumopod.com/x', $session->payment_link_url);

        $this->assertSame(
            PaymentIntentDecision::Allowed->value,
            PaymentIntent::query()->whereKey($session->payment_intent_id)->sole()->decision,
        );

        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/api/v1/payments'));
    }

    public function test_online_submit_without_an_order_fails_closed_without_calling_the_provider(): void
    {
        $this->withPaymentGate(open: true);
        Http::fake();

        $draftId = $this->journeyToStepEight()->get('draftId');

        Livewire::test(BookingWizard::class, ['draftId' => $draftId])
            ->call('openOnlinePayment')
            ->assertSet('onlinePaymentError', 'Pesanan belum dapat dibayar secara online. Tim kami akan membuat pesanan resmi dan mengirimkan penawaran harga sebelum pembayaran dapat dibuka. Gunakan pembayaran manual atau hubungi dukungan.')
            ->assertSet('currentStep', BookingWizardStep::PAYMENT);

        $this->assertSame(0, PaymentSession::query()->count());
        Http::assertNothingSent();
    }

    /**
     * Whole-branch review finding I-1 regression at the wizard seam: a
     * resumed wizard on an already-paid order must NOT open a second session
     * — the customer gets honest copy instead, and no provider call happens.
     */
    public function test_online_submit_on_an_already_paid_order_is_refused_without_opening_a_second_session(): void
    {
        $this->withPaymentGate(open: true);
        Http::fake();

        $draftId = $this->journeyToStepEight()->get('draftId');
        $this->satisfiedOrderFor($draftId, OrderStatus::DIBAYAR);

        Livewire::test(BookingWizard::class, ['draftId' => $draftId])
            ->call('openOnlinePayment')
            ->assertSet('onlinePaymentError', 'Pesanan ini telah dibayar dan tidak perlu dibayar lagi.')
            ->assertSet('currentStep', BookingWizardStep::PAYMENT);

        $this->assertSame(0, PaymentSession::query()->count());
        Http::assertNothingSent();
    }

    public function test_online_submit_for_an_unconfirmed_order_is_denied_without_calling_the_provider(): void
    {
        $this->withPaymentGate(open: true);
        Http::fake();

        $draftId = $this->journeyToStepEight()->get('draftId');

        Order::query()->create([
            'reference' => 'MK-ORD-1',
            'product_type' => ProductType::AT_NEED_SERVICE_ORDER->value,
            'status' => OrderStatus::MASUK->value,
            'booking_draft_id' => $draftId,
        ]);

        Livewire::test(BookingWizard::class, ['draftId' => $draftId])
            ->call('openOnlinePayment')
            ->assertSet('onlinePaymentError', 'Pembayaran online belum dapat dibuka saat ini karena konfirmasi pesanan, penawaran harga, atau otorisasi pembayaran belum lengkap. Gunakan pembayaran manual atau hubungi dukungan.')
            ->assertSet('currentStep', BookingWizardStep::PAYMENT);

        $this->assertSame(0, PaymentSession::query()->count());
        Http::assertNothingSent();
    }

    public function test_a_second_online_submit_does_not_open_a_second_session(): void
    {
        $this->withPaymentGate(open: true);
        $this->fakeProviderSuccess();

        $draftId = $this->journeyToStepEight()->get('draftId');
        $this->satisfiedOrderFor($draftId);

        $component = Livewire::test(BookingWizard::class, ['draftId' => $draftId]);
        $component->call('openOnlinePayment');
        $component->call('openOnlinePayment');

        $this->assertSame(1, PaymentSession::query()->count());
        $this->assertSame(1, PaymentIntent::query()->count());
    }

    public function test_the_wizard_renders_the_webhook_driven_state_after_return(): void
    {
        $this->withPaymentGate(open: true);
        $this->fakeProviderSuccess();

        $draftId = $this->journeyToStepEight()->get('draftId');
        $this->satisfiedOrderFor($draftId);

        Livewire::test(BookingWizard::class, ['draftId' => $draftId])
            ->call('openOnlinePayment');

        // The webhook — not the browser return — moved the session terminal.
        // This lane's wiring is proven by WebhookPaidEffectsTest; here the
        // display is being proven, so the fixture writes the settled state.
        $session = PaymentSession::query()->sole();
        $session->forceFill(['state' => SessionState::Paid->value])->save();

        Livewire::test(BookingWizard::class, ['draftId' => $draftId])
            ->call('goToStep', BookingWizardStep::PAYMENT)
            ->assertSee('Pembayaran Anda telah kami terima')
            ->assertDontSee('Menunggu pembayaran Anda');
    }

    public function test_online_is_rejected_when_the_gate_is_closed(): void
    {
        $this->withPaymentGate(open: false);

        $this->journeyToStepEight()
            ->assertDontSee('Bayar Sekarang')
            ->call('saveStep8', BookingPaymentMethod::ONLINE)
            ->assertHasErrors(['payment_method']);
    }

    public function test_the_return_page_shows_the_webhook_driven_state_without_marking_paid(): void
    {
        $this->withoutVite();
        $this->withPaymentGate(open: true);
        $this->fakeProviderSuccess();
        $this->satisfiedOrderFor((string) (new StartBookingDraft)()->getKey());
        $this->openSession();

        $session = PaymentSession::query()->sole();
        $session->forceFill(['state' => SessionState::Paid->value])->save();

        $this->get(route('payments.return', ['payment_id' => 'uuid-1']))
            ->assertOk()
            ->assertSee('Pembayaran Anda telah kami terima');

        // Reading the state changed nothing.
        $this->assertSame(SessionState::Paid->value, PaymentSession::query()->sole()->state);
        $this->assertSame(1, PaymentSession::query()->count());
    }

    public function test_the_return_page_never_claims_success_from_hostile_params_even_with_a_live_session(): void
    {
        $this->withoutVite();
        $this->withPaymentGate(open: true);
        $this->fakeProviderSuccess();
        $this->satisfiedOrderFor((string) (new StartBookingDraft)()->getKey());
        $this->openSession();

        // The session is AWAITING_PAYMENT. A hostile "I have paid" query must
        // not conjure a paid page out of a URL.
        $body = (string) $this->get(route('payments.return', [
            'payment_id' => 'uuid-1',
            'status' => 'paid',
            'state' => 'DIBAYAR',
        ]))->assertOk()->getContent();

        $this->assertStringNotContainsString('telah kami terima', $body);
        $this->assertSame(SessionState::AwaitingPayment->value, PaymentSession::query()->sole()->state);
    }

    public function test_the_cancel_page_shows_an_expired_state_from_the_database(): void
    {
        $this->withoutVite();
        $this->withPaymentGate(open: true);
        $this->fakeProviderSuccess();
        $this->satisfiedOrderFor((string) (new StartBookingDraft)()->getKey());
        $this->openSession();

        $session = PaymentSession::query()->sole();
        $session->forceFill(['state' => SessionState::Expired->value])->save();

        $this->get(route('payments.cancel', ['payment_id' => 'uuid-1']))
            ->assertOk()
            ->assertSee('Sesi pembayaran telah kedaluwarsa');
    }
}
