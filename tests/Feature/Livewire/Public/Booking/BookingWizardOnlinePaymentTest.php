<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Public\Booking;

use App\Domain\Booking\Actions\StartBookingDraft;
use App\Domain\Booking\BookingPaymentMethod;
use App\Domain\Booking\BookingWizardStep;
use App\Domain\CemeteryDirectory\LaunchCityCode;
use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Domain\OrderWorkflow\Actions\ApplyPaidEffects;
use App\Domain\OrderWorkflow\Actions\RecordOrderStatusChange;
use App\Domain\OrderWorkflow\Models\Order;
use App\Domain\OrderWorkflow\OrderStatus;
use App\Domain\OrderWorkflow\PaidTrigger;
use App\Domain\OrderWorkflow\PaidTriggerSource;
use App\Domain\OrderWorkflow\ProductType;
use App\Domain\Quotation\Actions\AcceptQuote;
use App\Domain\Quotation\Models\Quote;
use App\Domain\Quotation\QuoteStatus;
use App\Domain\ServiceCatalog\Models\ServiceDefinition;
use App\Domain\ServiceCatalog\ServiceCode;
use App\Livewire\Public\Booking\BookingWizard;
use App\Models\User;
use App\Platform\FeatureGate\Contracts\GateRegistrySource;
use App\Platform\FeatureGate\FeatureGateResolver;
use App\Platform\FeatureGate\GateRegistrySnapshot;
use App\Platform\FeatureGate\GateState;
use App\Platform\FeatureGate\ModeResolver;
use App\Platform\FeatureGate\Modes\PaymentMode;
use App\Platform\FinancialLedger\Money;
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
 * Task 6's booking Step 8 online branch, on the P0 submission chain: when
 * `G-PAY-01` is open (dev), the Step 8 online option submits the draft as an
 * idempotent Order (`SubmitBookingDraft`), issues a current Quote when the
 * order has none (`ComposeQuoteLinesFromBookingDraft` -> `IssueQuote` — the
 * chain's quote lines are SERVICE-VERSION lines resolved from each selected
 * service's current price version, per the P0 ruling), then calls
 * `OpenPaymentSession` with the quote total and redirects the customer to
 * the hosted checkout. The gate-closed behaviour is untouched (the
 * manual fallback is covered by the existing suites, which must stay
 * green).
 *
 * The six-condition guard's preconditions — confirmed order, accepted
 * unexpired quote, authorized opener with an ORDER-scope grant, matching
 * amount, bound merchant, open gate — are operator-side acts (the
 * off-screen confirmation journey), so a customer's first click on a fresh
 * draft creates the order and its quote and then fails closed honestly;
 * the redirect happens once those prerequisites exist. The fixtures below
 * arrange exactly that: the wizard's chain creates order + quote, then
 * `operatorCompletes()` walks the real status transitions, accepts the
 * quote and grants the ORDER scope — exactly as `OpenPaymentSessionTest`
 * arranges the same preconditions.
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
     * Step 8 (PAYMENT). `$services` selects Step 4's services — the two
     * seeded BASIC services by default, plus any additional ones a test
     * needs (Step 4 validation always requires the basics).
     *
     * @param  list<array{code: string, quantity: int}>  $services
     */
    private function journeyToStepEight(array $services = [
        ['code' => 'DOCUMENT_PROCESSING', 'quantity' => 1],
        ['code' => 'GRAVE_DIGGING', 'quantity' => 1],
    ]): Testable
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
            ->call('saveStep4', $services)
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

    /**
     * The order the wizard's chain submitted for the draft — idempotent on
     * `booking:{draft}:submit`, so `sole()` is exactly one.
     */
    private function chainOrderFor(string $draftId): Order
    {
        return Order::query()->where('booking_draft_id', $draftId)->firstOrFail();
    }

    /**
     * The six-condition guard's operator-side half, walked with the REAL
     * domain Actions on the order the chain created: the status transitions
     * MASUK -> ... -> PENAWARAN_TERKIRIM (and optionally on to
     * MENUNGGU_PEMBAYARAN), acceptance of the current quote, and an admin
     * actor holding the ORDER-scope grant. Never a fixture that cheats past
     * the model guards.
     *
     * Fixed 18 Aug 2026: this used to leave the test's own session
     * authenticated as the admin it creates here (`$this->actingAs($user)`
     * with no matching logout), so every caller's SUBSEQUENT
     * `openOnlinePayment()` call — meant to represent the CUSTOMER's own
     * click — actually ran as that admin. That silently validated "an admin
     * can pay," which was never broken, while `AuthorizeOrderPaymentOpening`
     * required the calling actor to hold the grant it had JUST checked the
     * CALLER'S OWN identity against — masking that no real (guest) customer
     * could ever pass condition 4. The admin setup now explicitly logs back
     * out before returning, so every caller's next action runs as the guest
     * a real customer actually is.
     */
    private function operatorCompletes(Order $order, OrderStatus $target = OrderStatus::PENAWARAN_TERKIRIM): void
    {
        $record = app(RecordOrderStatusChange::class);
        $record($order, OrderStatus::DIVERIFIKASI, 'actor:admin-1', 'admin');
        $record($order, OrderStatus::MENUNGGU_KETERSEDIAAN, 'actor:admin-1', 'admin');
        $record($order, OrderStatus::PENAWARAN_TERKIRIM, 'actor:admin-1', 'admin');

        $quote = Quote::query()
            ->where('order_id', $order->getKey())
            ->where('status', '!=', QuoteStatus::SUPERSEDED->value)
            ->firstOrFail();
        app(AcceptQuote::class)($quote, 'actor:customer-1');

        if ($target === OrderStatus::MENUNGGU_PEMBAYARAN) {
            $record($order, OrderStatus::DISETUJUI_PEMESAN, 'actor:admin-1', 'admin');
            $record($order, OrderStatus::MENUNGGU_PEMBAYARAN, 'actor:admin-1', 'admin');
        }

        // The admin exists only to author the order-scope grant — condition
        // 4 now reads that grant as a property of the ORDER, not of whoever
        // is currently logged in (see AuthorizeOrderPaymentOpening's class
        // doc block). `actingAs()`/grant creation need the admin briefly
        // authenticated to go through the normal identity-resolution path,
        // but this method logs back out before returning, so every
        // caller's next action runs as the guest a real customer is.
        $user = User::factory()->create();
        app(GrantActorRole::class)($user->id, ActorRole::ADMIN, 'test', 1);
        $this->actingAs($user);
        $this->app->forgetInstance(ActorContextResolver::class);

        ScopeAssignment::query()->create([
            'actor_identifier' => (string) $user->id,
            'entity_type' => ScopeEntityType::ORDER,
            'entity_id' => $order->getKey(),
        ]);

        $this->app['auth']->guard('web')->logout();
        $this->app->forgetInstance(ActorContextResolver::class);
    }

    /**
     * The webhook authority's paid path, applied through the real
     * `ApplyPaidEffects` Action (the same one `ApplyPaymentSettlement`
     * calls) — the order moves to DIBAYAR only because the paid trigger's
     * amount matches the accepted quote total.
     */
    private function settleAsPaid(Order $order): void
    {
        $quote = Quote::query()
            ->where('order_id', $order->getKey())
            ->where('status', '!=', QuoteStatus::SUPERSEDED->value)
            ->firstOrFail();

        app(ApplyPaidEffects::class)($order, new PaidTrigger(
            source: PaidTriggerSource::Webhook,
            sourceId: 'uuid-1',
            businessKey: 'payment:uuid-1',
            amount: $quote->totalMinor(),
            currency: $quote->currency,
            occurredAt: CarbonImmutable::now(),
            actorRef: 'actor:admin-1',
            actorRole: 'admin',
        ));
    }

    /**
     * The sum of the current price versions for the given service codes —
     * the amount the wizard's composed quote lines must add up to.
     *
     * @param  list<string>  $codes
     */
    private function expectedTotalMinor(array $codes): int
    {
        $total = 0;

        foreach ($codes as $code) {
            $definition = ServiceDefinition::findByCode($code);
            $this->assertNotNull($definition);

            $price = $definition->currentPriceVersion();
            $this->assertNotNull($price);

            $total += Money::fromDecimal((string) $price->amount);
        }

        return $total;
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

    /**
     * ADR-0035 item 1's mitigation: "unmissable payment-step labelling ...
     * before any redirect to the sandbox." `setUp()` never overrides
     * `payment.default`, so it stays on `PaymentProviders::SUMOPOD_SANDBOX`
     * — the warning must show under exactly the config this whole test
     * class otherwise runs against.
     */
    public function test_the_sandbox_warning_shows_before_the_pay_now_button(): void
    {
        $this->withPaymentGate(open: true);

        $this->journeyToStepEight()
            ->assertSeeInOrder([
                'ANDA TIDAK AKAN MENGIRIM UANG SUNGGUHAN',
                'Bayar Sekarang',
            ])
            ->assertSee('simulasi (sandbox)');
    }

    public function test_the_sandbox_warning_does_not_show_for_a_non_sandbox_provider(): void
    {
        $this->withPaymentGate(open: true);
        // ADR-0036: the real production provider slug, no longer a
        // placeholder now that one exists.
        config(['payment.default' => PaymentProviders::SUMOPOD_LIVE]);

        $this->journeyToStepEight()
            ->assertSee('Bayar Sekarang')
            ->assertDontSee('ANDA TIDAK AKAN MENGIRIM UANG SUNGGUHAN');
    }

    public function test_submitting_online_opens_a_session_and_redirects_to_the_hosted_checkout(): void
    {
        $this->withPaymentGate(open: true);
        $this->fakeProviderSuccess();

        $draftId = $this->journeyToStepEight()->get('draftId');

        // First click: the chain submits the draft and issues its quote.
        // The guard honestly denies a fresh submission (confirmation,
        // acceptance and opening authorization are operator-side), so no
        // provider call happens yet.
        $component = Livewire::test(BookingWizard::class, ['draftId' => $draftId]);
        $component->call('openOnlinePayment');

        $order = $this->chainOrderFor($draftId);
        $this->assertSame(OrderStatus::MASUK->value, $order->status);

        $quote = Quote::query()->where('order_id', $order->getKey())->sole();
        $this->assertSame(QuoteStatus::ISSUED->value, $quote->status);
        $this->assertSame($this->expectedTotalMinor(['DOCUMENT_PROCESSING', 'GRAVE_DIGGING']), $quote->totalMinor()->toMinorInt());

        $this->assertSame(0, PaymentSession::query()->count());
        Http::assertNothingSent();

        // Operator-side prerequisites land (real transitions, real
        // acceptance, real grant), then the re-click passes the guard and
        // reaches the hosted checkout.
        $this->operatorCompletes($order);

        $component->call('openOnlinePayment')
            ->assertRedirect('https://checkout.sumopod.com/x');

        $session = PaymentSession::query()->sole();

        $this->assertSame(SessionState::AwaitingPayment->value, $session->state);
        $this->assertSame($quote->totalMinor()->toMinorInt(), $session->amount_minor);
        $this->assertSame(self::MERCHANT_REF, $session->merchant_ref);
        $this->assertSame('https://checkout.sumopod.com/x', $session->payment_link_url);

        $this->assertSame(
            PaymentIntentDecision::Allowed->value,
            PaymentIntent::query()->whereKey($session->payment_intent_id)->sole()->decision,
        );

        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/api/v1/payments'));
    }

    /**
     * P0 chain behaviour: a valid draft at Step 8 with no order yet is no
     * longer a give-up — the click CREATES the order and its quote through
     * the chain. Payment still fails closed until the operator-side
     * prerequisites exist, with the manual path as the live alternative.
     */
    public function test_online_submit_creates_order_and_quote_but_fails_closed_without_calling_the_provider(): void
    {
        $this->withPaymentGate(open: true);
        Http::fake();

        $draftId = $this->journeyToStepEight()->get('draftId');

        $component = Livewire::test(BookingWizard::class, ['draftId' => $draftId]);
        $component->call('openOnlinePayment')
            ->assertSet('onlinePaymentError', 'Pembayaran online belum dapat dibuka saat ini karena konfirmasi pesanan, penawaran harga, atau otorisasi pembayaran belum lengkap. Gunakan pembayaran manual atau hubungi dukungan.')
            ->assertSet('currentStep', BookingWizardStep::PAYMENT);

        $order = $this->chainOrderFor($draftId);

        $this->assertSame(1, Quote::query()->where('order_id', $order->getKey())->count());
        $this->assertSame(0, PaymentSession::query()->count());
        Http::assertNothingSent();

        // Re-click without operator-side state: the chain is idempotent —
        // the SAME order and quote, still honestly closed.
        $component->call('openOnlinePayment')
            ->assertSet('onlinePaymentError', 'Pembayaran online belum dapat dibuka saat ini karena konfirmasi pesanan, penawaran harga, atau otorisasi pembayaran belum lengkap. Gunakan pembayaran manual atau hubungi dukungan.');

        $this->assertSame(1, Order::query()->where('booking_draft_id', $draftId)->count());
        $this->assertSame(1, Quote::query()->where('order_id', $order->getKey())->count());
        $this->assertSame(0, PaymentSession::query()->count());
        Http::assertNothingSent();
    }

    /**
     * Whole-branch review finding I-1 regression at the wizard seam: a
     * resumed wizard on an already-paid order must NOT open a second session
     * — the customer gets honest copy instead, and no provider call happens.
     * On the P0 chain the already-paid state arises on the chain's own order:
     * the webhook authority settles it (DIBAYAR), and the idempotent re-click
     * resolves to that same order.
     */
    public function test_online_submit_on_an_already_paid_order_is_refused_without_opening_a_second_session(): void
    {
        $this->withPaymentGate(open: true);
        Http::fake();

        $draftId = $this->journeyToStepEight()->get('draftId');

        $component = Livewire::test(BookingWizard::class, ['draftId' => $draftId]);
        $component->call('openOnlinePayment');

        $order = $this->chainOrderFor($draftId);
        $this->operatorCompletes($order, OrderStatus::MENUNGGU_PEMBAYARAN);
        $this->settleAsPaid($order);

        $component->call('openOnlinePayment')
            ->assertSet('onlinePaymentError', 'Pesanan ini telah dibayar dan tidak perlu dibayar lagi.')
            ->assertSet('currentStep', BookingWizardStep::PAYMENT);

        $this->assertSame(0, PaymentSession::query()->count());
        $this->assertSame(OrderStatus::DIBAYAR->value, $order->fresh()->status);
        Http::assertNothingSent();
    }

    /**
     * The chain's own freshly-submitted order is unconfirmed (MASUK) and its
     * quote is issued but not accepted — the guard must evaluate that REAL
     * state and deny, recording a denial intent and never calling the
     * provider. The old fixture that pre-created a second MASUK order is
     * gone: the chain IS the order's only creation path on this screen, so
     * exactly one exists.
     */
    public function test_online_submit_for_an_unconfirmed_order_is_denied_without_calling_the_provider(): void
    {
        $this->withPaymentGate(open: true);
        Http::fake();

        $draftId = $this->journeyToStepEight()->get('draftId');

        Livewire::test(BookingWizard::class, ['draftId' => $draftId])
            ->call('openOnlinePayment')
            ->assertSet('onlinePaymentError', 'Pembayaran online belum dapat dibuka saat ini karena konfirmasi pesanan, penawaran harga, atau otorisasi pembayaran belum lengkap. Gunakan pembayaran manual atau hubungi dukungan.')
            ->assertSet('currentStep', BookingWizardStep::PAYMENT);

        $this->assertSame(1, Order::query()->where('booking_draft_id', $draftId)->count());
        $this->assertSame(OrderStatus::MASUK->value, $this->chainOrderFor($draftId)->status);

        // The guard's denied evaluation is recorded — the denial is a real,
        // audited decision, never a silent no-op.
        $this->assertSame(
            PaymentIntentDecision::Denied->value,
            PaymentIntent::query()->sole()->decision,
        );
        $this->assertSame(0, PaymentSession::query()->count());
        Http::assertNothingSent();
    }

    /**
     * One journey, three clicks: the first submits the chain (one denied
     * evaluation), the second opens the session after the operator-side
     * prerequisites land, and the third re-points at the stored session.
     * Exactly ONE order (idempotency key), ONE quote (version 1) and ONE
     * session come out of it — never a duplicate write anywhere in the
     * chain.
     */
    public function test_reclicking_bayar_sekarang_is_idempotent(): void
    {
        $this->withPaymentGate(open: true);
        $this->fakeProviderSuccess();

        $draftId = $this->journeyToStepEight()->get('draftId');

        $component = Livewire::test(BookingWizard::class, ['draftId' => $draftId]);
        $component->call('openOnlinePayment');

        $order = $this->chainOrderFor($draftId);
        $this->operatorCompletes($order);

        $component->call('openOnlinePayment')
            ->assertRedirect('https://checkout.sumopod.com/x');
        $component->call('openOnlinePayment')
            ->assertRedirect('https://checkout.sumopod.com/x');

        $this->assertSame(1, Order::query()->where('booking_draft_id', $draftId)->count());
        $this->assertSame(1, Quote::query()->where('order_id', $order->getKey())->count());
        $this->assertSame(1, Quote::query()->where('order_id', $order->getKey())->where('version_number', 1)->count());
        $this->assertSame(1, PaymentSession::query()->count());
        $this->assertSame(
            1,
            PaymentIntent::query()->where('decision', PaymentIntentDecision::Allowed->value)->count(),
        );
    }

    public function test_the_wizard_renders_the_webhook_driven_state_after_return(): void
    {
        $this->withPaymentGate(open: true);
        $this->fakeProviderSuccess();

        $draftId = $this->journeyToStepEight()->get('draftId');

        $component = Livewire::test(BookingWizard::class, ['draftId' => $draftId]);
        $component->call('openOnlinePayment');
        $this->operatorCompletes($this->chainOrderFor($draftId));
        $component->call('openOnlinePayment');

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

    /**
     * P0 chain fail-closed: a selected service whose price was superseded
     * (the Step 5 "harga belum tersedia" state) must not produce a quote
     * with a fabricated amount — the honest copy lands, no session is
     * opened, no provider call happens, and the manual fallback stays on
     * screen. The submission itself (the order at MASUK) is a legitimate
     * `SubmitBookingDraft` result; only the quote was impossible.
     */
    public function test_an_unpriced_service_fails_closed_and_keeps_the_manual_path(): void
    {
        $this->withPaymentGate(open: true);
        Http::fake();

        // FLOWERS' seeded price is superseded — the one legal price-version
        // mutation — so the service now has no current price to quote.
        $flowers = ServiceDefinition::findByCode(ServiceCode::FLOWERS);
        $this->assertNotNull($flowers);
        $flowersPrice = $flowers->currentPriceVersion();
        $this->assertNotNull($flowersPrice);
        $flowersPrice->forceFill(['superseded_at' => CarbonImmutable::now()])->save();

        $draftId = $this->journeyToStepEight([
            ['code' => 'DOCUMENT_PROCESSING', 'quantity' => 1],
            ['code' => 'GRAVE_DIGGING', 'quantity' => 1],
            ['code' => 'FLOWERS', 'quantity' => 1],
        ])->get('draftId');

        Livewire::test(BookingWizard::class, ['draftId' => $draftId])
            ->call('openOnlinePayment')
            ->assertSet('onlinePaymentError', 'Pembayaran online belum dapat dibuka karena harga layanan belum tersedia. Gunakan pembayaran manual atau hubungi dukungan.')
            ->assertSet('currentStep', BookingWizardStep::PAYMENT)
            ->assertSee('Pembayaran Manual');

        $this->assertSame(1, Order::query()->where('booking_draft_id', $draftId)->count());
        $this->assertSame(0, Quote::query()->count());
        $this->assertSame(0, PaymentSession::query()->count());
        Http::assertNothingSent();
    }

    /**
     * The wizard's provider-failure catch, on the chain: with the operator
     * prerequisites in place the guard passes, and a provider refusal then
     * surfaces the "layanan tidak tersedia" copy with the manual path still
     * live — no session is recorded for an aborted opening.
     */
    public function test_a_provider_failure_fails_closed_and_keeps_the_manual_path(): void
    {
        $this->withPaymentGate(open: true);

        $draftId = $this->journeyToStepEight()->get('draftId');

        $component = Livewire::test(BookingWizard::class, ['draftId' => $draftId]);
        $component->call('openOnlinePayment');
        $this->operatorCompletes($this->chainOrderFor($draftId));

        Http::fake([
            'api-pay-sandbox.sumopod.com/api/v1/payments' => Http::response(['error' => 'bad'], 400),
        ]);

        $component->call('openOnlinePayment')
            ->assertSet('onlinePaymentError', 'Layanan pembayaran online sedang tidak tersedia. Silakan coba lagi atau gunakan pembayaran manual.')
            ->assertSet('currentStep', BookingWizardStep::PAYMENT)
            ->assertSee('Pembayaran Manual');

        $this->assertSame(0, PaymentSession::query()->count());
        $this->assertSame(0, PaymentIntent::query()->where('decision', PaymentIntentDecision::Allowed->value)->count());
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

    /**
     * `Actions\ReconcilePaymentSession`, wired into the wizard's own
     * `onlinePaymentDisplayState()` — see that method's doc block for why
     * this is the right integration point (a reliable, PHP-session-scoped
     * reference to which session to check, never the URL). Re-rendering the
     * same component the customer's browser lands back on after the
     * provider redirect must reconcile a still-`AWAITING_PAYMENT` session
     * and reflect the settlement in that SAME render.
     */
    public function test_re_rendering_after_returning_from_checkout_reconciles_a_completed_payment(): void
    {
        $this->withPaymentGate(open: true);
        $this->fakeProviderSuccess();

        $draftId = $this->journeyToStepEight()->get('draftId');
        $component = Livewire::test(BookingWizard::class, ['draftId' => $draftId]);
        $component->call('openOnlinePayment');

        $order = $this->chainOrderFor($draftId);
        // `MENUNGGU_PEMBAYARAN`, not the default `PENAWARAN_TERKIRIM`: the
        // six-condition guard accepts either (`GuardPaymentSession::
        // CONFIRMED_STATUSES` includes both), but `ApplyPaidEffects`'s own
        // order-transition state machine does not — `PENAWARAN_TERKIRIM ->
        // DIBAYAR` directly is an illegal transition (skips
        // `DISETUJUI_PEMESAN`/`MENUNGGU_PEMBAYARAN`). This only surfaces once
        // something actually settles the order, which the OTHER online-path
        // tests never do (they stop at "redirected to checkout").
        $this->operatorCompletes($order, OrderStatus::MENUNGGU_PEMBAYARAN);
        $component->call('openOnlinePayment')->assertRedirect('https://checkout.sumopod.com/x');

        $session = PaymentSession::query()->sole();
        $this->assertSame(SessionState::AwaitingPayment->value, $session->state);

        Http::fake([
            'api-pay-sandbox.sumopod.com/api/v1/payments/uuid-1' => Http::response([
                'payment_id' => 'uuid-1',
                'order_id' => $order->reference,
                'amount' => 1_500_000,
                'fee' => 38_800,
                'net_amount' => 1_461_200,
                'status' => 'completed',
                'payment_method' => 'qris',
                'completed_at' => now()->toIso8601String(),
            ], 200),
        ]);

        // A fresh component instance mirrors what actually happens: the
        // customer's browser returns to this draft's own page (a full
        // navigation), which mounts a NEW BookingWizard instance that reads
        // the SAME stored PHP-session key — not the same PHP object.
        Livewire::test(BookingWizard::class, ['draftId' => $draftId]);

        $this->assertSame(OrderStatus::DIBAYAR->value, $order->fresh()->status);
        $this->assertSame(SessionState::Paid->value, $session->fresh()->state);
    }

    /**
     * Repeated renders within the cooldown window must not turn every
     * Livewire re-render into an outbound provider API call.
     */
    public function test_reconciliation_is_throttled_across_repeated_renders(): void
    {
        $this->withPaymentGate(open: true);
        $this->fakeProviderSuccess();

        $draftId = $this->journeyToStepEight()->get('draftId');
        $component = Livewire::test(BookingWizard::class, ['draftId' => $draftId]);
        $component->call('openOnlinePayment');

        $order = $this->chainOrderFor($draftId);
        $this->operatorCompletes($order);
        $component->call('openOnlinePayment')->assertRedirect('https://checkout.sumopod.com/x');

        Http::fake([
            'api-pay-sandbox.sumopod.com/api/v1/payments/uuid-1' => Http::response([
                'payment_id' => 'uuid-1',
                'order_id' => $order->reference,
                'amount' => 1_500_000,
                'fee' => 38_800,
                'net_amount' => 1_461_200,
                'status' => 'pending',
            ], 200),
        ]);

        Livewire::test(BookingWizard::class, ['draftId' => $draftId]);
        Livewire::test(BookingWizard::class, ['draftId' => $draftId]);
        Livewire::test(BookingWizard::class, ['draftId' => $draftId]);

        Http::assertSentCount(1);
    }
}
