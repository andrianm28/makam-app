<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Platform\Payment\Checkout\Contracts\PaymentCheckoutClient;
use App\Platform\Payment\Checkout\CreatePaymentRequest;
use App\Platform\Payment\Checkout\Exceptions\PaymentCheckoutUnavailableException;
use App\Platform\Payment\PaymentProviders;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The checkout client is only as safe as its configuration source. ADR-0033:
 * the credential is env-injected, never committed — so a deployment that has
 * not been given a key must fail closed before any HTTP request is made
 * (`config/payment.php`'s own "missing credential must fail closed" rule).
 *
 * These tests resolve the client the way production does — through the
 * container binding — and drive its configuration to the empty values an
 * unprovisioned environment produces.
 */
final class CheckoutConfigFailClosedTest extends TestCase
{
    private const string PROVIDER = 'payment.providers.'.PaymentProviders::SUMOPOD_SANDBOX;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
    }

    public function test_checkout_fails_closed_when_the_api_key_is_unset(): void
    {
        config([self::PROVIDER.'.api_key' => '']);

        $client = app(PaymentCheckoutClient::class);

        $this->expectException(PaymentCheckoutUnavailableException::class);
        $client->createPayment(new CreatePaymentRequest(orderId: 'ord-1', amountMinor: 100));
    }

    public function test_checkout_fails_closed_when_the_provider_url_is_unset(): void
    {
        config([self::PROVIDER.'.base_url' => '']);

        $client = app(PaymentCheckoutClient::class);

        $this->expectException(PaymentCheckoutUnavailableException::class);
        $client->createPayment(new CreatePaymentRequest(orderId: 'ord-1', amountMinor: 100));
    }

    public function test_checkout_sends_no_http_when_it_fails_closed(): void
    {
        config([self::PROVIDER.'.api_key' => '']);

        $client = app(PaymentCheckoutClient::class);

        try {
            $client->createPayment(new CreatePaymentRequest(orderId: 'ord-1', amountMinor: 100));
            $this->fail('Expected PaymentCheckoutUnavailableException to be thrown.');
        } catch (PaymentCheckoutUnavailableException) {
            // Expected: the refusal is before any provider call.
        }

        Http::assertNothingSent();
    }
}
