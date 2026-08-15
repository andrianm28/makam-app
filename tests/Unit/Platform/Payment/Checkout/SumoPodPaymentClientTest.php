<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Payment\Checkout;

use App\Platform\Payment\Checkout\CreatePaymentRequest;
use App\Platform\Payment\Checkout\Exceptions\PaymentCheckoutProviderException;
use App\Platform\Payment\Checkout\Exceptions\PaymentCheckoutUnavailableException;
use App\Platform\Payment\Checkout\SumoPodPaymentClient;
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
                'amount' => 5500000,
                'fee' => 38800,
                'net_amount' => 5461200,
                'payment_link_url' => 'https://checkout.sumopod.com/x',
                'status' => 'pending',
                'expires_at' => '2026-08-16T12:00:00+07:00',
            ], 201),
        ]);

        $result = $this->client()->createPayment(
            new CreatePaymentRequest(orderId: 'ord-1', amountMinor: 5500000)
        );

        $this->assertSame('uuid-1', $result->paymentId);
        $this->assertSame('ord-1', $result->orderId);
        $this->assertSame(5500000, $result->amountMinor);
        $this->assertSame(38800, $result->feeMinor);
        $this->assertSame(5461200, $result->netAmountMinor);
        $this->assertSame('https://checkout.sumopod.com/x', $result->paymentLinkUrl);
        $this->assertSame('pending', $result->status);
        $this->assertInstanceOf(CarbonImmutable::class, $result->expiresAt);
        $this->assertSame('2026-08-16 12:00:00', $result->expiresAt->format('Y-m-d H:i:s'));

        Http::assertSent(function (Request $request): bool {
            return $request->url() === self::PROVIDER_URL.'/api/v1/payments'
                && $request->header('X-Api-Key') === ['test-key']
                && $request['order_id'] === 'ord-1'
                && $request['amount'] === 5500000
                && $request['currency'] === 'IDR';
        });
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
        $this->client()->createPayment(new CreatePaymentRequest(orderId: 'ord-1', amountMinor: 5500000));
    }
}
