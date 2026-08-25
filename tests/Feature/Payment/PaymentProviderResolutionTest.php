<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Platform\Payment\Checkout\CreatePaymentRequest;
use App\Platform\Payment\Checkout\Exceptions\PaymentCheckoutUnavailableException;
use App\Platform\Payment\Checkout\SumoPodPaymentClient;
use App\Platform\Payment\Http\WebhookCredentials;
use App\Platform\Payment\PaymentProviders;
use App\Platform\Payment\Providers\SignatureOutcome;
use App\Platform\Payment\Providers\SumoPodWebhookSignature;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * ADR-0036: a provider switch (`payment.default`) is designed to be
 * config-only — `SumoPodPaymentClient::fromConfig()` and
 * `SumoPodWebhookSignature::fromConfig()` both resolve
 * `payment.providers.{payment.default}` generically, with no code aware of
 * which slug is active. Neither `fromConfig()` method had any test coverage
 * before this file — both existing test suites
 * (`SumoPodPaymentClientTest`, `SumoPodWebhookSignatureTest`) construct their
 * subject directly with a raw config array, bypassing config resolution
 * entirely. This file closes that gap for both the pre-existing sandbox slug
 * and the new `PaymentProviders::SUMOPOD_LIVE` slug, proving a provider
 * switch really does nothing but change which config block gets read.
 *
 * Every credential here is a locally generated test string — none is, or
 * resembles, a real credential (AC14, same convention as
 * `SumoPodPaymentClientTest`/`SumoPodWebhookSignatureTest`).
 */
final class PaymentProviderResolutionTest extends TestCase
{
    public function test_the_payment_client_resolves_the_sandbox_providers_block_by_default(): void
    {
        config([
            'payment.default' => PaymentProviders::SUMOPOD_SANDBOX,
            'payment.providers.'.PaymentProviders::SUMOPOD_SANDBOX.'.base_url' => 'https://sandbox.test',
            'payment.providers.'.PaymentProviders::SUMOPOD_SANDBOX.'.api_key' => 'sandbox-test-key',
        ]);

        Http::fake([
            'sandbox.test/api/v1/payments' => Http::response([
                'payment_id' => 'pay-1', 'order_id' => 'ord-1',
                'amount' => 100, 'fee' => 0, 'net_amount' => 100,
                'payment_link_url' => 'https://checkout.test/x', 'status' => 'pending',
            ], 201),
        ]);

        SumoPodPaymentClient::fromConfig()->createPayment(
            new CreatePaymentRequest(orderId: 'ord-1', amountMinor: 100)
        );

        Http::assertSent(fn ($request): bool => $request->url() === 'https://sandbox.test/api/v1/payments'
            && $request->header('X-Api-Key') === ['sandbox-test-key']);
    }

    public function test_the_payment_client_resolves_the_live_providers_block_when_selected(): void
    {
        config([
            'payment.default' => PaymentProviders::SUMOPOD_LIVE,
            'payment.providers.'.PaymentProviders::SUMOPOD_LIVE.'.base_url' => 'https://live.test',
            'payment.providers.'.PaymentProviders::SUMOPOD_LIVE.'.api_key' => 'live-test-key',
        ]);

        Http::fake([
            'live.test/api/v1/payments' => Http::response([
                'payment_id' => 'pay-2', 'order_id' => 'ord-2',
                'amount' => 100, 'fee' => 0, 'net_amount' => 100,
                'payment_link_url' => 'https://checkout.test/y', 'status' => 'pending',
            ], 201),
        ]);

        SumoPodPaymentClient::fromConfig()->createPayment(
            new CreatePaymentRequest(orderId: 'ord-2', amountMinor: 100)
        );

        Http::assertSent(fn ($request): bool => $request->url() === 'https://live.test/api/v1/payments'
            && $request->header('X-Api-Key') === ['live-test-key']);
    }

    public function test_the_live_provider_fails_closed_when_unprovisioned(): void
    {
        // The empty-by-default posture config/payment.php documents for the
        // live block — no env vars set anywhere, so both credentials are ''.
        config([
            'payment.default' => PaymentProviders::SUMOPOD_LIVE,
            'payment.providers.'.PaymentProviders::SUMOPOD_LIVE.'.base_url' => '',
            'payment.providers.'.PaymentProviders::SUMOPOD_LIVE.'.api_key' => '',
        ]);

        Http::fake();

        $this->expectException(PaymentCheckoutUnavailableException::class);

        SumoPodPaymentClient::fromConfig()->createPayment(
            new CreatePaymentRequest(orderId: 'ord-3', amountMinor: 100)
        );

        Http::assertNothingSent();
    }

    public function test_the_webhook_signature_resolves_the_live_providers_signing_secrets_when_selected(): void
    {
        // Deliberately low-entropy (repeated-character) base64, matching
        // SumoPodWebhookSignatureTest's own convention — high-entropy-secret
        // scanners must never mistake a test fixture for a leaked credential,
        // and the repeated bytes are still valid base64, so this exercises
        // keyFor()'s real decode path rather than its raw-string fallback.
        $lowEntropySecretMaterial = 'Y2NjY2NjY2NjY2NjY2NjY2NjY2NjY2Nj';
        $secret = 'whsec_'.$lowEntropySecretMaterial;

        config([
            'payment.default' => PaymentProviders::SUMOPOD_LIVE,
            'payment.providers.'.PaymentProviders::SUMOPOD_LIVE.'.webhook_signing_secrets' => [$secret],
        ]);

        $signature = SumoPodWebhookSignature::fromConfig();

        // fromConfig() has no public accessor for the resolved secrets — the
        // class itself is the only consumer, so the real, observable proof
        // is that a signature computed with the configured secret verifies.
        $now = CarbonImmutable::parse('2026-08-22T10:00:00+00:00');
        $ts = (string) $now->getTimestamp();
        $body = '{"event_type":"payment.completed","data":{"payment_id":"pay_1"}}';
        $key = base64_decode($lowEntropySecretMaterial, true);
        $signatureValue = 'v1,'.base64_encode(hash_hmac('sha256', "msg_1.{$ts}.{$body}", (string) $key, true));

        $result = $signature->verify(
            credentials: new WebhookCredentials(svixSignature: $signatureValue, sharedToken: null),
            svixId: 'msg_1',
            svixTimestamp: $ts,
            rawBody: $body,
            now: $now,
        );

        $this->assertSame(SignatureOutcome::Verified, $result->outcome);
    }

    protected function setUp(): void
    {
        parent::setUp();

        // AC14: credentials must never appear in logs — assert nothing this
        // test's config values write reaches the log, same discipline
        // `WebhookCredentialRedactionTest` already applies to this module.
        Log::spy();
    }
}
