<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Domain\OrderWorkflow\Models\Order;
use App\Domain\OrderWorkflow\OrderStatus;
use App\Domain\OrderWorkflow\ProductType;
use App\Domain\Quotation\Models\Quote;
use App\Domain\Quotation\QuoteStatus;
use App\Models\User;
use App\Platform\Audit\AuditOutcome;
use App\Platform\Audit\Models\AuditEvent;
use App\Platform\FeatureGate\Contracts\GateRegistrySource;
use App\Platform\FeatureGate\FeatureGateResolver;
use App\Platform\FeatureGate\GateRegistrySnapshot;
use App\Platform\FeatureGate\GateState;
use App\Platform\FeatureGate\ModeResolver;
use App\Platform\IdentityAccess\ActorContextResolver;
use App\Platform\IdentityAccess\Roles\Actions\GrantActorRole;
use App\Platform\IdentityAccess\Roles\ActorRole;
use App\Platform\IdentityAccess\Scopes\Models\ScopeAssignment;
use App\Platform\IdentityAccess\Scopes\ScopeEntityType;
use App\Platform\Payment\Actions\OpenPaymentSession;
use App\Platform\Payment\Actions\OpenPaymentSessionCommand;
use App\Platform\Payment\Checkout\Exceptions\PaymentCheckoutProviderException;
use App\Platform\Payment\Exceptions\PaymentSessionMerchantMismatchException;
use App\Platform\Payment\Exceptions\PaymentSessionOpeningDeniedException;
use App\Platform\Payment\Exceptions\PaymentSessionOrderAlreadyPaidException;
use App\Platform\Payment\Exceptions\PaymentSessionOrderNotFoundException;
use App\Platform\Payment\Models\PaymentIntent;
use App\Platform\Payment\Models\PaymentSession;
use App\Platform\Payment\OrderType;
use App\Platform\Payment\PaymentAuditActions;
use App\Platform\Payment\PaymentIntentDecision;
use App\Platform\Payment\PaymentProviders;
use App\Platform\Payment\SessionState;
use App\Platform\SiteSettings\Models\SiteSetting;
use App\Platform\SiteSettings\SettingsService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Task 4's `OpenPaymentSession` — the ONLY production path that creates a
 * `payment_sessions` row, composed of the six-condition guard, the SumoPod
 * checkout client, and one atomic intent+session+audit write.
 *
 * The gate is driven through the same fake `GateRegistrySource` the guard
 * suite uses; the merchant binding through `config('payment.merchant_ref')` /
 * `config('payment.badan_usaha_ref')` — the FIN-DEC-01 provisioning channel
 * the approved design spec makes real via config. Every key in this file is
 * a locally generated test string.
 */
final class OpenPaymentSessionTest extends TestCase
{
    use RefreshDatabase;

    private const string MERCHANT_REF = 'mk-merchant-dev';

    private const string BADAN_USAHA_REF = 'badan-usaha-dev';

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
     * Stub the provider's hosted-checkout endpoint. Each test registers
     * exactly ONE fake — Laravel's `Http::fake()` merges stubs (first match
     * wins), so a setUp-wide success stub would shadow a per-test failure
     * stub.
     */
    private function fakeProviderResponse(array $body, int $status = 201): void
    {
        Http::fake([
            'api-pay-sandbox.sumopod.com/api/v1/payments' => Http::response($body, $status),
        ]);
    }

    private function fakeProviderSuccess(): void
    {
        $this->fakeProviderResponse([
            'payment_id' => 'uuid-1',
            'order_id' => 'MK-ORD-1',
            // Whole rupiah — the provider's wire unit (Rp 1.500.000), never
            // the 150_000_000 minor units the session snapshot holds.
            'amount' => 1_500_000,
            'fee' => 38_800,
            'net_amount' => 1_461_200,
            'payment_link_url' => 'https://checkout.sumopod.com/x',
            'status' => 'pending',
        ]);
    }

    private function guardWithPaymentGate(bool $open): void
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
    }

    private function makeOrder(OrderStatus $status = OrderStatus::PENAWARAN_TERKIRIM): Order
    {
        return Order::query()->create([
            'reference' => 'MK-ORD-1',
            'product_type' => ProductType::AT_NEED_SERVICE_ORDER->value,
            'status' => $status->value,
        ]);
    }

    private function acceptedQuote(Order $order, int $totalMinor = 1_500_000_00): void
    {
        $quote = Quote::query()->create([
            'order_id' => $order->getKey(),
            'version_number' => 1,
            'status' => QuoteStatus::ISSUED->value,
            'total_minor' => $totalMinor,
            'currency' => 'IDR',
            'issued_at' => CarbonImmutable::now(),
            'expires_at' => CarbonImmutable::now()->addDays(7),
            'issued_by_ref' => 'actor:admin-1',
            'issued_by_role' => 'admin',
        ]);

        $quote->accept(CarbonImmutable::now(), 'actor:admin-1');
    }

    private function adminActor(): User
    {
        $user = User::factory()->create();

        app(GrantActorRole::class)(
            $user->id,
            ActorRole::ADMIN,
            'test',
            1,
        );

        $this->actingAs($user);
        $this->app->forgetInstance(ActorContextResolver::class);

        return $user;
    }

    private function grantOrderScope(string $actorRef, Order $order): void
    {
        ScopeAssignment::query()->create([
            'actor_identifier' => $actorRef,
            'entity_type' => ScopeEntityType::ORDER,
            'entity_id' => $order->getKey(),
        ]);
    }

    /**
     * A fully satisfied booking order: confirmed, accepted unexpired quote,
     * an admin holding the ORDER grant, and an amount that matches the quote
     * total. With the gate open and the merchant binding configured, all six
     * guard conditions hold.
     */
    private function fullySatisfiedOrder(): Order
    {
        $order = $this->makeOrder();
        $this->acceptedQuote($order);
        $user = $this->adminActor();
        $this->grantOrderScope((string) $user->id, $order);

        return $order;
    }

    private function command(array $overrides = []): OpenPaymentSessionCommand
    {
        return new OpenPaymentSessionCommand(
            orderType: $overrides['orderType'] ?? OrderType::Booking,
            orderRef: $overrides['orderRef'] ?? 'MK-ORD-1',
            amountMinor: $overrides['amountMinor'] ?? 1_500_000_00,
            merchantRef: $overrides['merchantRef'] ?? self::MERCHANT_REF,
            successReturnUrl: $overrides['successReturnUrl'] ?? 'https://makam.test/payment/success',
            cancelReturnUrl: $overrides['cancelReturnUrl'] ?? 'https://makam.test/payment/cancelled',
        );
    }

    public function test_gate_open_with_all_preconditions_creates_a_session_from_the_provider_link(): void
    {
        $this->guardWithPaymentGate(open: true);
        $this->fullySatisfiedOrder();
        $this->fakeProviderSuccess();

        $session = app(OpenPaymentSession::class)($this->command());

        $this->assertInstanceOf(PaymentSession::class, $session);
        $this->assertSame(SessionState::AwaitingPayment->value, $session->state);
        $this->assertSame('uuid-1', $session->provider_payment_id);
        $this->assertSame('https://checkout.sumopod.com/x', $session->payment_link_url);
        $this->assertSame(1_500_000_00, $session->amount_minor);
        $this->assertSame('IDR', $session->currency);
        $this->assertSame(self::MERCHANT_REF, $session->merchant_ref);
        $this->assertSame(self::BADAN_USAHA_REF, $session->badan_usaha_ref);

        $this->assertDatabaseHas('payment_sessions', [
            'id' => $session->id,
            'state' => SessionState::AwaitingPayment->value,
            'provider' => PaymentProviders::SUMOPOD_SANDBOX,
            'provider_payment_id' => 'uuid-1',
            'payment_link_url' => 'https://checkout.sumopod.com/x',
            'amount_minor' => 1_500_000_00,
            'currency' => 'IDR',
            'merchant_ref' => self::MERCHANT_REF,
            'badan_usaha_ref' => self::BADAN_USAHA_REF,
        ]);
    }

    public function test_the_opening_writes_an_allowed_intent_and_audit_in_the_same_transaction(): void
    {
        $this->guardWithPaymentGate(open: true);
        $this->fullySatisfiedOrder();
        $this->fakeProviderSuccess();

        $session = app(OpenPaymentSession::class)($this->command());

        $intent = PaymentIntent::query()->whereKey($session->payment_intent_id)->sole();

        $this->assertSame(PaymentIntentDecision::Allowed->value, $intent->decision);
        $this->assertSame(1_500_000_00, $intent->requested_amount_minor);
        $this->assertSame('IDR', $intent->currency);
        $this->assertSame('online', $intent->payment_mode);
        $this->assertNull($intent->denied_condition);
        $this->assertNull($intent->denial_reason);
        $this->assertNull($intent->missing_upstream);
        $this->assertNull($intent->denied_conditions);
        $this->assertNull($intent->public_message);

        $event = AuditEvent::query()
            ->where('action', PaymentAuditActions::SESSION_OPENED)
            ->sole();

        $this->assertSame(AuditOutcome::Allowed->value, $event->outcome);
        $this->assertSame('payment_session', $event->subject_type);
        $this->assertSame((string) $session->id, $event->subject_id);
        $this->assertSame(1, AuditEvent::query()->where('action', PaymentAuditActions::SESSION_OPENED)->count());
    }

    public function test_a_closed_gate_surfaces_the_guard_denial_without_creating_a_session(): void
    {
        $this->guardWithPaymentGate(open: false);
        $this->fullySatisfiedOrder();
        Http::fake();

        try {
            app(OpenPaymentSession::class)($this->command());
            $this->fail('Expected PaymentSessionOpeningDeniedException to be thrown.');
        } catch (PaymentSessionOpeningDeniedException $exception) {
            $this->assertStringContainsString(
                'Online payment is not currently available',
                $exception->publicMessage(),
            );
        }

        $this->assertSame(0, PaymentSession::query()->count());
        $this->assertSame(1, PaymentIntent::query()->count(), 'The guard records the denial intent.');
        $this->assertSame(
            PaymentIntentDecision::Denied->value,
            PaymentIntent::query()->sole()->decision,
        );
        Http::assertNothingSent();
    }

    public function test_an_unknown_order_reference_is_refused_before_the_guard(): void
    {
        $this->guardWithPaymentGate(open: true);
        Http::fake();

        try {
            app(OpenPaymentSession::class)($this->command(['orderRef' => 'MK-NOPE']));
            $this->fail('Expected PaymentSessionOrderNotFoundException to be thrown.');
        } catch (PaymentSessionOrderNotFoundException $exception) {
            $this->assertStringContainsString('MK-NOPE', $exception->getMessage());
        }

        $this->assertSame(0, PaymentSession::query()->count());
        $this->assertSame(0, PaymentIntent::query()->count());
        Http::assertNothingSent();
    }

    public function test_an_amount_mismatch_is_denied_by_the_guard(): void
    {
        $this->guardWithPaymentGate(open: true);
        $this->fullySatisfiedOrder();
        Http::fake();

        try {
            app(OpenPaymentSession::class)($this->command(['amountMinor' => 1_500_000_01]));
            $this->fail('Expected PaymentSessionOpeningDeniedException to be thrown.');
        } catch (PaymentSessionOpeningDeniedException) {
            // The guard's condition 5 denies: the requested amount must match the quote total.
        }

        $this->assertSame(0, PaymentSession::query()->count());
        Http::assertNothingSent();
    }

    public function test_a_merchant_ref_that_is_not_the_bound_merchant_fails_closed(): void
    {
        $this->guardWithPaymentGate(open: true);
        $this->fullySatisfiedOrder();
        Http::fake();

        try {
            app(OpenPaymentSession::class)($this->command(['merchantRef' => 'some-other-merchant']));
            $this->fail('Expected PaymentSessionMerchantMismatchException to be thrown.');
        } catch (PaymentSessionMerchantMismatchException) {
            // A session must never open under a merchant this deployment does not serve.
        }

        $this->assertSame(0, PaymentSession::query()->count());
        $this->assertSame(0, PaymentIntent::query()->count(), 'An allowed evaluation writes nothing until the session commits.');
        Http::assertNothingSent();
    }

    public function test_a_db_set_merchant_ref_is_the_bound_merchant_across_the_read_paths(): void
    {
        // Review fix (round 1) regression: condition 6, assertMerchantBound
        // and the session row's badan_usaha_ref must all resolve a DB-set
        // value through SettingsService — an operator-managed
        // `payment_merchant_ref` row must not diverge from
        // assertMerchantBound's read path (which would throw an uncatchable
        // PaymentSessionMerchantMismatchException on every booking).
        $this->guardWithPaymentGate(open: true);
        $this->fullySatisfiedOrder();
        $this->fakeProviderSuccess();
        config(['payment.merchant_ref' => null, 'payment.badan_usaha_ref' => null]);

        SiteSetting::query()->create([
            'key' => SiteSetting::KEY_PAYMENT_MERCHANT_REF,
            'value' => 'mk-merchant-db',
        ]);
        SiteSetting::query()->create([
            'key' => SiteSetting::KEY_PAYMENT_BADAN_USAHA_REF,
            'value' => 'badan-usaha-db',
        ]);

        $this->assertSame(
            'mk-merchant-db',
            app(SettingsService::class)->setting(SiteSetting::KEY_PAYMENT_MERCHANT_REF, 'fallback'),
        );

        $session = app(OpenPaymentSession::class)($this->command(['merchantRef' => 'mk-merchant-db']));

        $this->assertInstanceOf(PaymentSession::class, $session);
        $this->assertSame('mk-merchant-db', $session->merchant_ref);
        $this->assertSame('badan-usaha-db', $session->badan_usaha_ref);
    }

    /**
     * Marketplace order-type coverage lives in
     * `tests/Feature/Payment/OpenPaymentSessionMarketplaceTest.php` — the
     * marketplace follow-up ended the permanent refusal this test used to
     * assert (`OrderType::Marketplace` is now a fully supported opening
     * path through `GuardMarketplacePaymentOpening`).
     */

    /**
     * Whole-branch review finding I-1 regression: a DIBAYAR order satisfies
     * all six guard conditions, so without this precondition a resumed
     * wizard could open a SECOND session and collect a second payment that
     * `ApplyPaidEffects` would silently swallow. The opening must be refused
     * before the guard, before any provider call, and the refusal must be
     * audited so an operator can see the attempt.
     */
    public function test_an_already_paid_order_cannot_open_a_new_session(): void
    {
        $this->guardWithPaymentGate(open: true);
        $order = $this->makeOrder(OrderStatus::DIBAYAR);
        $this->acceptedQuote($order);
        $user = $this->adminActor();
        $this->grantOrderScope((string) $user->id, $order);
        Http::fake();

        try {
            app(OpenPaymentSession::class)($this->command());
            $this->fail('Expected PaymentSessionOrderAlreadyPaidException to be thrown.');
        } catch (PaymentSessionOrderAlreadyPaidException $exception) {
            $this->assertStringContainsString('MK-ORD-1', $exception->getMessage());
        }

        // No session, no intent (the refusal precedes the guard, so it is
        // not a guard evaluation), and no provider call.
        $this->assertSame(0, PaymentSession::query()->count());
        $this->assertSame(0, PaymentIntent::query()->count());
        Http::assertNothingSent();

        // The refusal is audited: subject = the order, outcome denied.
        $audit = AuditEvent::query()
            ->where('action', PaymentAuditActions::SESSION_OPENING_REFUSED)
            ->sole();
        $this->assertSame('order', $audit->subject_type);
        $this->assertSame((string) $order->getKey(), $audit->subject_id);
        $this->assertSame('denied', $audit->outcome);
    }

    public function test_a_provider_failure_leaves_no_intent_and_no_session(): void
    {
        $this->guardWithPaymentGate(open: true);
        $this->fullySatisfiedOrder();
        $this->fakeProviderResponse(['error' => 'bad'], 400);

        try {
            app(OpenPaymentSession::class)($this->command());
            $this->fail('Expected PaymentCheckoutProviderException to be thrown.');
        } catch (PaymentCheckoutProviderException) {
            // Expected: the provider refused; nothing may be recorded.
        }

        $this->assertSame(0, PaymentSession::query()->count());
        $this->assertSame(0, PaymentIntent::query()->count());
    }

    public function test_the_session_records_the_guard_verified_amount_not_a_divergent_provider_echo(): void
    {
        $this->guardWithPaymentGate(open: true);
        $this->fullySatisfiedOrder();

        $this->fakeProviderResponse([
            'payment_id' => 'uuid-1',
            'order_id' => 'MK-ORD-1',
            'amount' => 999,
            'fee' => 0,
            'net_amount' => 999,
            'payment_link_url' => 'https://checkout.sumopod.com/x',
            'status' => 'pending',
        ]);

        $session = app(OpenPaymentSession::class)($this->command());

        $this->assertSame(1_500_000_00, $session->amount_minor);
        $this->assertSame(
            1_500_000_00,
            PaymentIntent::query()->whereKey($session->payment_intent_id)->sole()->requested_amount_minor,
        );
    }
}
