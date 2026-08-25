<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Domain\GraveRegistry\GraveRecordAccessMode;
use App\Domain\GraveRegistry\Models\GraveRecord;
use App\Domain\Renewal\Models\Renewal;
use App\Domain\Renewal\Models\RenewalQuote;
use App\Platform\Audit\AuditOutcome;
use App\Platform\Audit\Models\AuditEvent;
use App\Platform\FeatureGate\Contracts\GateRegistrySource;
use App\Platform\FeatureGate\FeatureGateResolver;
use App\Platform\FeatureGate\GateRegistrySnapshot;
use App\Platform\FeatureGate\GateState;
use App\Platform\FeatureGate\ModeResolver;
use App\Platform\Payment\Actions\OpenPaymentSession;
use App\Platform\Payment\Actions\OpenPaymentSessionCommand;
use App\Platform\Payment\Checkout\Exceptions\PaymentCheckoutProviderException;
use App\Platform\Payment\Exceptions\PaymentSessionMerchantMismatchException;
use App\Platform\Payment\Exceptions\PaymentSessionOpeningDeniedException;
use App\Platform\Payment\Exceptions\PaymentSessionOrderNotFoundException;
use App\Platform\Payment\Models\PaymentIntent;
use App\Platform\Payment\Models\PaymentSession;
use App\Platform\Payment\OrderType;
use App\Platform\Payment\PaymentAuditActions;
use App\Platform\Payment\PaymentProviders;
use App\Platform\Payment\SessionState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The renewal follow-up to `OpenPaymentSessionTest`/
 * `OpenPaymentSessionMarketplaceTest`: `OrderType::Renewal` opening through
 * `App\Domain\Renewal\Actions\GuardRenewalPaymentOpening` instead of the
 * booking six-condition guard or the marketplace four-condition guard.
 * Mirrors those two files' fixture/assertion style so all three order
 * types' coverage stays comparable.
 *
 * The single most important test in this file is
 * `test_manual_coordination_required_refuses_without_creating_a_session` —
 * it is the one that proves the plan's own explicit Global Constraint
 * ("`manualCoordinationRequired: true` must never reach
 * `PaymentSession::create()`") actually holds against a real guard
 * evaluation and a real (Postgres) database, not just in code review.
 */
final class OpenRenewalPaymentSessionTest extends TestCase
{
    use RefreshDatabase;

    private const string MERCHANT_REF = 'mk-merchant-dev';

    private const string BADAN_USAHA_REF = 'badan-usaha-dev';

    private const string RENEWAL_REFERENCE = 'PPJ-TEST-0001';

    private const int QUOTE_AMOUNT_MINOR = 150_000_00;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'payment.merchant_ref' => self::MERCHANT_REF,
            'payment.badan_usaha_ref' => self::BADAN_USAHA_REF,
            'payment.providers.'.PaymentProviders::SUMOPOD_SANDBOX.'.api_key' => 'test-key',
        ]);
    }

    private function fakeProviderResponse(array $body, int $status = 201): void
    {
        Http::fake([
            'api-pay-sandbox.sumopod.com/api/v1/payments' => Http::response($body, $status),
        ]);
    }

    private function fakeProviderSuccess(): void
    {
        $this->fakeProviderResponse([
            'payment_id' => 'uuid-renewal-1',
            'order_id' => self::RENEWAL_REFERENCE,
            // Whole rupiah — the provider's wire unit (Rp 150.000), the
            // major-unit form of self::QUOTE_AMOUNT_MINOR (150_000_00).
            'amount' => 150_000,
            'fee' => 1_350,
            'net_amount' => 148_650,
            'payment_link_url' => 'https://checkout.sumopod.com/renewal',
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

    /**
     * A fully eligible renewal: grave published (OPEN access), an
     * accepted-and-unexpired quote for `$amountMinor`, reference
     * `self::RENEWAL_REFERENCE`. Every test that wants a passing guard
     * builds from this and overrides only what it needs to fail.
     */
    private function makeEligibleRenewal(int $amountMinor = self::QUOTE_AMOUNT_MINOR): Renewal
    {
        $grave = GraveRecord::factory()->create(['access_mode' => GraveRecordAccessMode::OPEN]);
        $renewal = Renewal::factory()->create([
            'grave_record_id' => $grave->id,
            'reference' => self::RENEWAL_REFERENCE,
        ]);
        RenewalQuote::factory()->accepted()->create([
            'renewal_id' => $renewal->id,
            'amount_minor' => $amountMinor,
        ]);

        return $renewal;
    }

    private function command(array $overrides = []): OpenPaymentSessionCommand
    {
        return new OpenPaymentSessionCommand(
            orderType: OrderType::Renewal,
            orderRef: $overrides['orderRef'] ?? self::RENEWAL_REFERENCE,
            amountMinor: $overrides['amountMinor'] ?? self::QUOTE_AMOUNT_MINOR,
            merchantRef: $overrides['merchantRef'] ?? self::MERCHANT_REF,
            successReturnUrl: $overrides['successReturnUrl'] ?? 'https://makam.test/payment/success',
            cancelReturnUrl: $overrides['cancelReturnUrl'] ?? 'https://makam.test/payment/cancelled',
        );
    }

    public function test_gate_open_with_all_preconditions_creates_a_session_from_the_provider_link(): void
    {
        $this->guardWithPaymentGate(open: true);
        $this->makeEligibleRenewal();
        $this->fakeProviderSuccess();

        $session = app(OpenPaymentSession::class)($this->command());

        $this->assertInstanceOf(PaymentSession::class, $session);
        $this->assertSame(SessionState::AwaitingPayment->value, $session->state);
        $this->assertSame('uuid-renewal-1', $session->provider_payment_id);
        $this->assertSame('https://checkout.sumopod.com/renewal', $session->payment_link_url);
        $this->assertSame(self::QUOTE_AMOUNT_MINOR, $session->amount_minor);
        $this->assertSame(self::MERCHANT_REF, $session->merchant_ref);
        $this->assertSame(self::BADAN_USAHA_REF, $session->badan_usaha_ref);

        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/api/v1/payments')
            && $request['order_id'] === self::RENEWAL_REFERENCE);
    }

    public function test_the_opening_writes_an_allowed_intent_and_audit(): void
    {
        $this->guardWithPaymentGate(open: true);
        $this->makeEligibleRenewal();
        $this->fakeProviderSuccess();

        $session = app(OpenPaymentSession::class)($this->command());

        $intent = PaymentIntent::query()->whereKey($session->payment_intent_id)->sole();
        $this->assertSame('allowed', $intent->decision);

        $event = AuditEvent::query()
            ->where('action', PaymentAuditActions::SESSION_OPENED)
            ->sole();
        $this->assertSame(AuditOutcome::Allowed->value, $event->outcome);
        $this->assertSame('payment_session', $event->subject_type);
    }

    /**
     * The single most important negative test in this file — see the class
     * doc block. G-PAY-01 closed, the renewal is otherwise fully eligible
     * (published grave, accepted+unexpired quote, matching amount), so
     * `GuardRenewalPaymentOpening` returns `isAllowed() === true` with
     * `isManualCoordinationRequired() === true`. That result must never
     * reach `PaymentSession::create()`.
     */
    public function test_manual_coordination_required_refuses_without_creating_a_session(): void
    {
        $this->guardWithPaymentGate(open: false);
        $this->makeEligibleRenewal();
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
        Http::assertNothingSent();
    }

    public function test_a_grave_that_is_not_published_denies_without_creating_a_session(): void
    {
        $this->guardWithPaymentGate(open: true);
        $grave = GraveRecord::factory()->create(['access_mode' => GraveRecordAccessMode::CLOSED]);
        $renewal = Renewal::factory()->create([
            'grave_record_id' => $grave->id,
            'reference' => self::RENEWAL_REFERENCE,
        ]);
        RenewalQuote::factory()->accepted()->create([
            'renewal_id' => $renewal->id,
            'amount_minor' => self::QUOTE_AMOUNT_MINOR,
        ]);
        Http::fake();

        try {
            app(OpenPaymentSession::class)($this->command());
            $this->fail('Expected PaymentSessionOpeningDeniedException to be thrown.');
        } catch (PaymentSessionOpeningDeniedException $exception) {
            $this->assertStringContainsString('not available for online renewal', $exception->publicMessage());
        }

        $this->assertSame(0, PaymentSession::query()->count());
        Http::assertNothingSent();
    }

    public function test_a_stale_quote_denies_without_creating_a_session(): void
    {
        $this->guardWithPaymentGate(open: true);
        $grave = GraveRecord::factory()->create(['access_mode' => GraveRecordAccessMode::OPEN]);
        $renewal = Renewal::factory()->create([
            'grave_record_id' => $grave->id,
            'reference' => self::RENEWAL_REFERENCE,
        ]);
        RenewalQuote::factory()->expired()->create([
            'renewal_id' => $renewal->id,
            'amount_minor' => self::QUOTE_AMOUNT_MINOR,
        ]);
        Http::fake();

        try {
            app(OpenPaymentSession::class)($this->command());
            $this->fail('Expected PaymentSessionOpeningDeniedException to be thrown.');
        } catch (PaymentSessionOpeningDeniedException $exception) {
            $this->assertStringContainsString('accepted and unexpired quote', $exception->publicMessage());
        }

        $this->assertSame(0, PaymentSession::query()->count());
        Http::assertNothingSent();
    }

    public function test_an_amount_mismatch_denies_without_creating_a_session(): void
    {
        $this->guardWithPaymentGate(open: true);
        $this->makeEligibleRenewal();
        Http::fake();

        try {
            app(OpenPaymentSession::class)($this->command(['amountMinor' => self::QUOTE_AMOUNT_MINOR + 1]));
            $this->fail('Expected PaymentSessionOpeningDeniedException to be thrown.');
        } catch (PaymentSessionOpeningDeniedException $exception) {
            $this->assertStringContainsString('does not match the quoted total', $exception->publicMessage());
        }

        $this->assertSame(0, PaymentSession::query()->count());
        Http::assertNothingSent();
    }

    public function test_an_unknown_renewal_reference_is_refused_before_the_guard(): void
    {
        $this->guardWithPaymentGate(open: true);
        Http::fake();

        try {
            app(OpenPaymentSession::class)($this->command(['orderRef' => 'PPJ-NOPE']));
            $this->fail('Expected PaymentSessionOrderNotFoundException to be thrown.');
        } catch (PaymentSessionOrderNotFoundException $exception) {
            $this->assertStringContainsString('PPJ-NOPE', $exception->getMessage());
        }

        $this->assertSame(0, PaymentSession::query()->count());
        Http::assertNothingSent();
    }

    /**
     * Confirms the shared merchant-binding check
     * (`OpenPaymentSession::assertMerchantBound()`) still runs for the
     * renewal branch — i.e. that a real `PaymentSession` row's
     * `merchant_ref` is genuinely bound to `config('payment.merchant_ref')`
     * and this is not accidentally bypassed for `OrderType::Renewal`. The
     * happy-path test above already asserts the POSITIVE case (the created
     * session carries the bound merchant); this is the negative case, that
     * an unbound claim still fails closed before any session is created.
     */
    public function test_a_merchant_ref_that_is_not_the_bound_merchant_fails_closed(): void
    {
        $this->guardWithPaymentGate(open: true);
        $this->makeEligibleRenewal();
        Http::fake();

        try {
            app(OpenPaymentSession::class)($this->command(['merchantRef' => 'some-other-merchant']));
            $this->fail('Expected PaymentSessionMerchantMismatchException to be thrown.');
        } catch (PaymentSessionMerchantMismatchException) {
            // A session must never open under a merchant this deployment does not serve.
        }

        $this->assertSame(0, PaymentSession::query()->count());
        Http::assertNothingSent();
    }

    public function test_a_provider_failure_leaves_no_session(): void
    {
        $this->guardWithPaymentGate(open: true);
        $this->makeEligibleRenewal();
        $this->fakeProviderResponse(['error' => 'bad'], 400);

        try {
            app(OpenPaymentSession::class)($this->command());
            $this->fail('Expected PaymentCheckoutProviderException to be thrown.');
        } catch (PaymentCheckoutProviderException) {
            // Expected: the provider refused; nothing may be recorded.
        }

        $this->assertSame(0, PaymentSession::query()->count());
    }
}
