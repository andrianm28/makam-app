<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Payment\Checkout;

use App\Platform\Payment\Checkout\CreatePaymentRequest;
use App\Platform\Payment\Checkout\Exceptions\PaymentCheckoutProviderException;
use App\Platform\Payment\Checkout\Exceptions\PaymentCheckoutUnavailableException;
use App\Platform\Payment\Checkout\SumoPodPaymentClient;
use App\Platform\Payment\WebhookEnvelope;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * ADR-0033 §Decision: "Create payment: `POST /api/v1/payments` with an
 * `X-Api-Key` header." The client is the only outbound caller of the provider
 * in this module, isolated behind `PaymentCheckoutClient` so a provider switch
 * is config-only.
 *
 * Every key used in this file is a locally generated test string. None is, or
 * resembles, a real sandbox credential — AC14 forbids a real one appearing in
 * a fixture (same convention as `SumoPodWebhookSignatureTest`).
 */
final class SumoPodPaymentClientTest extends TestCase
{
    private const string PROVIDER_URL = 'https://api-pay-sandbox.sumopod.com';

    private function client(array $config = []): SumoPodPaymentClient
    {
        return new SumoPodPaymentClient([
            'provider_url' => self::PROVIDER_URL,
            'api_key' => 'test-key',
            ...$config,
        ]);
    }

    public function test_create_payment_posts_to_the_provider_and_returns_the_hosted_link(): void
    {
        Http::fake([
            'api-pay-sandbox.sumopod.com/api/v1/payments' => Http::response([
                'payment_id' => 'uuid-1',
                'order_id' => 'ord-1',
                // Whole rupiah — the provider's wire unit. The fixture keeps
                // ADR-0033's fee arithmetic honest: 0.7% x 5,500,000 + Rp 300
                // = 38,800, and net = 5,500,000 - 38,800 = 5,461,200.
                'amount' => 5_500_000,
                'fee' => 38_800,
                'net_amount' => 5_461_200,
                'payment_link_url' => 'https://checkout.sumopod.com/x',
                'status' => 'pending',
                'expires_at' => '2026-08-16T12:00:00+07:00',
            ], 201),
        ]);

        $result = $this->client()->createPayment(
            new CreatePaymentRequest(orderId: 'ord-1', amountMinor: 550_000_000)
        );

        $this->assertSame('uuid-1', $result->paymentId);
        $this->assertSame('ord-1', $result->orderId);
        $this->assertSame(550_000_000, $result->amountMinor);
        $this->assertSame(3_880_000, $result->feeMinor);
        $this->assertSame(546_120_000, $result->netAmountMinor);
        $this->assertSame('https://checkout.sumopod.com/x', $result->paymentLinkUrl);
        $this->assertSame('pending', $result->status);
        $this->assertInstanceOf(CarbonImmutable::class, $result->expiresAt);
        $this->assertSame('2026-08-16 12:00:00', $result->expiresAt->format('Y-m-d H:i:s'));

        Http::assertSent(function (Request $request): bool {
            return $request->url() === self::PROVIDER_URL.'/api/v1/payments'
                && $request->header('X-Api-Key') === ['test-key']
                && $request['order_id'] === 'ord-1'
                // The outbound amount is WHOLE RUPIAH (5,500,000), never the
                // 550,000,000 minor units the module collects internally.
                && $request['amount'] === 5_500_000
                && $request['currency'] === 'IDR';
        });
    }

    /**
     * The cross-check the whole-branch review demanded: the OUTBOUND wire
     * convention (minor units -> whole rupiah on the request) and the INBOUND
     * webhook convention (`WebhookEnvelope` -> `Money::fromDecimal()` ->
     * minor units) must pin each other. The same Rp 1.500.000 that leaves as
     * the request `amount` 1500000 must arrive in the webhook as
     * `data.amount` 1500000 and convert back to the very same 150_000_000
     * minor units the session snapshot holds. If either half's unit drifts,
     * this test fails.
     */
    public function test_the_outbound_wire_units_pin_against_the_inbound_webhook_convention(): void
    {
        $amountMinor = 150_000_000;

        Http::fake([
            'api-pay-sandbox.sumopod.com/api/v1/payments' => Http::response([
                'payment_id' => 'uuid-roundtrip',
                'order_id' => 'ord-roundtrip',
                'amount' => 1_500_000,
                'fee' => 10_800,
                'net_amount' => 1_489_200,
                'payment_link_url' => 'https://checkout.sumopod.com/x',
                'status' => 'pending',
            ], 201),
        ]);

        $result = $this->client()->createPayment(
            new CreatePaymentRequest(orderId: 'ord-roundtrip', amountMinor: $amountMinor)
        );

        Http::assertSent(fn (Request $request): bool => $request['amount'] === 1_500_000);

        $this->assertSame($amountMinor, $result->amountMinor);

        // The inbound twin: the provider's webhook carries the SAME whole
        // rupiah value as decimal rupiah, and `WebhookEnvelope` converts it
        // back to the minor units a payment session snapshot holds.
        $envelope = WebhookEnvelope::parse(json_encode([
            'event_type' => 'payment.completed',
            'data' => [
                'payment_id' => 'uuid-roundtrip',
                'order_id' => 'ord-roundtrip',
                'amount' => 1_500_000,
                'fee' => 10_800,
                'net_amount' => 1_489_200,
                'status' => 'completed',
                'completed_at' => '2026-08-14T09:59:00+00:00',
            ],
        ], JSON_THROW_ON_ERROR));

        $this->assertTrue($envelope->isWellFormed());
        $this->assertSame($amountMinor, $envelope->amountMinor);
    }

    public function test_create_payment_sends_the_optional_fields_only_when_given(): void
    {
        Http::fake([
            'api-pay-sandbox.sumopod.com/api/v1/payments' => Http::response([
                'payment_id' => 'uuid-2',
                'order_id' => 'ord-2',
                'amount' => 100,
                'fee' => 0,
                'net_amount' => 100,
                'payment_link_url' => 'https://checkout.sumopod.com/y',
                'status' => 'pending',
            ], 201),
        ]);

        $this->client()->createPayment(
            new CreatePaymentRequest(
                orderId: 'ord-2',
                amountMinor: 100,
                currency: 'IDR',
                successReturnUrl: 'https://makam.test/booking/success',
                cancelReturnUrl: 'https://makam.test/booking/cancel',
                expiresInHours: 6,
            )
        );

        Http::assertSent(function (Request $request): bool {
            return $request['success_return_url'] === 'https://makam.test/booking/success'
                && $request['cancel_return_url'] === 'https://makam.test/booking/cancel'
                && $request['expires_in_hours'] === 6;
        });
    }

    public function test_create_payment_fails_closed_when_the_key_is_unset(): void
    {
        Http::fake();

        $client = new SumoPodPaymentClient([
            'provider_url' => self::PROVIDER_URL,
            'api_key' => '',
        ]);

        $this->expectException(PaymentCheckoutUnavailableException::class);
        $client->createPayment(new CreatePaymentRequest(orderId: 'ord-1', amountMinor: 100));
    }

    public function test_create_payment_fails_closed_when_the_provider_url_is_unset(): void
    {
        Http::fake();

        $client = new SumoPodPaymentClient([
            'provider_url' => '',
            'api_key' => 'test-key',
        ]);

        $this->expectException(PaymentCheckoutUnavailableException::class);
        $client->createPayment(new CreatePaymentRequest(orderId: 'ord-1', amountMinor: 100));
    }

    public function test_create_payment_surfaces_provider_errors(): void
    {
        Http::fake(['*' => Http::response(['error' => 'bad'], 400)]);

        $this->expectException(PaymentCheckoutProviderException::class);
        $this->client()->createPayment(new CreatePaymentRequest(orderId: 'ord-1', amountMinor: 100));
    }

    public function test_create_payment_surfaces_a_connection_failure_as_a_provider_error(): void
    {
        Http::fake([
            '*' => static fn (): never => throw new ConnectionException('Connection refused'),
        ]);

        $this->expectException(PaymentCheckoutProviderException::class);
        $this->client()->createPayment(new CreatePaymentRequest(orderId: 'ord-1', amountMinor: 100));
    }

    public function test_create_payment_rejects_a_malformed_response_body(): void
    {
        Http::fake([
            'api-pay-sandbox.sumopod.com/api/v1/payments' => Http::response('not-json', 200),
        ]);

        $this->expectException(PaymentCheckoutProviderException::class);
        $this->client()->createPayment(new CreatePaymentRequest(orderId: 'ord-1', amountMinor: 100));
    }

    public function test_create_payment_rejects_a_response_missing_a_required_field(): void
    {
        Http::fake([
            'api-pay-sandbox.sumopod.com/api/v1/payments' => Http::response([
                'payment_id' => 'uuid-1',
                'order_id' => 'ord-1',
                'amount' => 5500000,
                'fee' => 38800,
                'net_amount' => 5461200,
                'status' => 'pending',
            ], 201),
        ]);

        $this->expectException(PaymentCheckoutProviderException::class);
        $this->client()->createPayment(new CreatePaymentRequest(orderId: 'ord-1', amountMinor: 550000000));
    }

    /**
     * The provider's response amounts are whole rupiah; a value with real
     * fractional content is refused exactly like `WebhookEnvelope` refuses it
     * inbound — never rounded into a value the session comparison would trust.
     */
    public function test_create_payment_rejects_a_response_amount_with_real_fractional_content(): void
    {
        Http::fake([
            'api-pay-sandbox.sumopod.com/api/v1/payments' => Http::response([
                'payment_id' => 'uuid-1',
                'order_id' => 'ord-1',
                'amount' => 1_500_000.125,
                'fee' => 10_800,
                'net_amount' => 1_489_200,
                'payment_link_url' => 'https://checkout.sumopod.com/x',
                'status' => 'pending',
            ], 201),
        ]);

        $this->expectException(PaymentCheckoutProviderException::class);
        $this->client()->createPayment(new CreatePaymentRequest(orderId: 'ord-1', amountMinor: 150_000_000));
    }

    /**
     * A JSON `1500000.00` decodes to the exact double 1500000.0, which is a
     * lossless whole-rupiah value — the same shape `WebhookEnvelope` accepts
     * inbound. Both halves of the boundary must agree on what is convertible.
     */
    public function test_create_payment_accepts_an_exactly_integral_float_response_amount(): void
    {
        Http::fake([
            'api-pay-sandbox.sumopod.com/api/v1/payments' => Http::response([
                'payment_id' => 'uuid-1',
                'order_id' => 'ord-1',
                'amount' => 1_500_000.00,
                'fee' => 0.0,
                'net_amount' => 1_500_000.0,
                'payment_link_url' => 'https://checkout.sumopod.com/x',
                'status' => 'pending',
            ], 201),
        ]);

        $result = $this->client()->createPayment(
            new CreatePaymentRequest(orderId: 'ord-1', amountMinor: 150_000_000)
        );

        $this->assertSame(150_000_000, $result->amountMinor);
        $this->assertSame(0, $result->feeMinor);
        $this->assertSame(150_000_000, $result->netAmountMinor);
    }

    /**
     * A minor-unit amount with a nonzero sen component cannot be expressed in
     * the provider's whole-rupiah wire unit. Refused before any HTTP request,
     * never rounded: a rounded amount would silently change what is collected.
     */
    public function test_create_payment_refuses_a_request_amount_that_is_not_whole_rupiah(): void
    {
        Http::fake();

        $this->expectException(PaymentCheckoutProviderException::class);
        $this->client()->createPayment(new CreatePaymentRequest(orderId: 'ord-1', amountMinor: 123_45));

        Http::assertNothingSent();
    }

    /**
     * `fetchStatus()` — the reconciliation half added for
     * `Actions\ReconcilePaymentSession`. The endpoint path is an inference
     * from REST convention (see `SumoPodPaymentClient::STATUS_PATH`'s doc
     * block); this test pins the inferred contract, not a confirmed one.
     */
    public function test_fetch_status_gets_the_status_endpoint_and_returns_the_provider_record(): void
    {
        Http::fake([
            'api-pay-sandbox.sumopod.com/api/v1/payments/uuid-1' => Http::response([
                'payment_id' => 'uuid-1',
                'order_id' => 'MK-2026-ABCDEFGH',
                'amount' => 900_000,
                'fee' => 6_600,
                'net_amount' => 893_400,
                'status' => 'completed',
                'payment_method' => 'qris',
                'completed_at' => '2026-08-25T09:30:00+00:00',
            ], 200),
        ]);

        $result = $this->client()->fetchStatus('uuid-1');

        $this->assertSame('uuid-1', $result->paymentId);
        $this->assertSame('MK-2026-ABCDEFGH', $result->orderId);
        $this->assertSame('completed', $result->status);
        $this->assertSame(90_000_000, $result->amountMinor);
        $this->assertSame(660_000, $result->feeMinor);
        $this->assertSame(89_340_000, $result->netAmountMinor);
        $this->assertSame('qris', $result->paymentMethod);
        $this->assertInstanceOf(CarbonImmutable::class, $result->completedAt);
        $this->assertSame('2026-08-25 09:30:00', $result->completedAt->format('Y-m-d H:i:s'));

        Http::assertSent(function (Request $request): bool {
            return $request->method() === 'GET'
                && $request->url() === self::PROVIDER_URL.'/api/v1/payments/uuid-1'
                && $request->header('X-Api-Key') === ['test-key'];
        });
    }

    public function test_fetch_status_returns_a_pending_status_with_no_completed_at(): void
    {
        Http::fake([
            'api-pay-sandbox.sumopod.com/api/v1/payments/uuid-pending' => Http::response([
                'payment_id' => 'uuid-pending',
                'order_id' => 'MK-2026-PENDING1',
                'amount' => 100_000,
                'fee' => 1_000,
                'net_amount' => 99_000,
                'status' => 'pending',
            ], 200),
        ]);

        $result = $this->client()->fetchStatus('uuid-pending');

        $this->assertSame('pending', $result->status);
        $this->assertNull($result->completedAt);
        $this->assertNull($result->paymentMethod);
    }

    public function test_fetch_status_fails_closed_when_the_key_is_unset(): void
    {
        Http::fake();

        $this->expectException(PaymentCheckoutUnavailableException::class);
        $this->client(['api_key' => ''])->fetchStatus('uuid-1');

        Http::assertNothingSent();
    }

    public function test_fetch_status_fails_closed_for_a_blank_payment_id(): void
    {
        Http::fake();

        $this->expectException(PaymentCheckoutProviderException::class);
        $this->client()->fetchStatus('   ');

        Http::assertNothingSent();
    }

    public function test_fetch_status_surfaces_provider_errors(): void
    {
        Http::fake(['*' => Http::response(['error' => 'not found'], 404)]);

        $this->expectException(PaymentCheckoutProviderException::class);
        $this->client()->fetchStatus('uuid-missing');
    }

    public function test_fetch_status_surfaces_a_connection_failure_as_a_provider_error(): void
    {
        Http::fake([
            'api-pay-sandbox.sumopod.com/api/v1/payments/uuid-1' => function (): void {
                throw new ConnectionException('timed out');
            },
        ]);

        $this->expectException(PaymentCheckoutProviderException::class);
        $this->client()->fetchStatus('uuid-1');
    }

    public function test_fetch_status_rejects_a_response_missing_a_required_field(): void
    {
        Http::fake([
            'api-pay-sandbox.sumopod.com/api/v1/payments/uuid-1' => Http::response([
                'payment_id' => 'uuid-1',
                'order_id' => 'MK-2026-ABCDEFGH',
                'amount' => 900_000,
                'fee' => 6_600,
                // 'net_amount' missing.
                'status' => 'completed',
            ], 200),
        ]);

        $this->expectException(PaymentCheckoutProviderException::class);
        $this->client()->fetchStatus('uuid-1');
    }
}
