<?php

declare(strict_types=1);

namespace App\Platform\Payment\Checkout;

use App\Platform\Payment\Checkout\Contracts\PaymentCheckoutClient;
use App\Platform\Payment\Checkout\Exceptions\PaymentCheckoutProviderException;
use App\Platform\Payment\Checkout\Exceptions\PaymentCheckoutUnavailableException;
use App\Platform\Payment\PaymentProviders;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * ADR-0033's outbound provider adapter: `POST /api/v1/payments` with an
 * `X-Api-Key` header, returning the hosted-checkout coordinates
 * (`payment_link_url`) for the pending payment.
 *
 * ---------------------------------------------------------------------------
 * Fails closed, never open
 * ---------------------------------------------------------------------------
 * The API key is a protected-injected secret (`config/payment.php` is the only
 * place the module reads it from the environment; `AGENTS.md` §Authentication
 * and uploads). An unprovisioned environment throws
 * `PaymentCheckoutUnavailableException` BEFORE any HTTP request — there is no
 * development shortcut, no fallback key. The provider URL is checked with the
 * same severity: a request to an empty URL is a configuration bug, not a
 * network call.
 *
 * ---------------------------------------------------------------------------
 * The provider is the only authority, so every failure is an exception
 * ---------------------------------------------------------------------------
 * A non-2xx status, a body that is not JSON, a body missing a required field,
 * or an unreachable provider all surface as `PaymentCheckoutProviderException`.
 * A caller can never receive a partially-valid `PaymentCheckoutResult` and
 * mistake it for a created payment — `PaymentIntent`/`PaymentSession` rows are
 * created by the guard-composed path (Task 4), which sees only the exception.
 *
 * ---------------------------------------------------------------------------
 * Configuration
 * ---------------------------------------------------------------------------
 * `fromConfig()` reads the ACTIVE provider's block in `config/payment.php`
 * (`payment.providers.{payment.default}`), same resolution as
 * `SumoPodWebhookSignature::fromConfig()` — so a provider switch or a rotated
 * key is config-only, and `config:cache`-safe (the credential is read from
 * config here; this module's only raw environment read lives in
 * `config/payment.php`).
 *
 * @param  array{provider_url: string, api_key: string}  $config
 */
final readonly class SumoPodPaymentClient implements PaymentCheckoutClient
{
    private const string CREATE_PAYMENT_PATH = '/api/v1/payments';

    /**
     * ADR-0033 §Decision response fields, all required for a usable hosted
     * checkout.
     *
     * @var list<string>
     */
    private const array REQUIRED_RESPONSE_FIELDS = [
        'payment_id',
        'order_id',
        'amount',
        'fee',
        'net_amount',
        'payment_link_url',
        'status',
    ];

    public function __construct(
        private array $config,
    ) {}

    public static function fromConfig(): self
    {
        $provider = (string) config('payment.default', PaymentProviders::SUMOPOD_SANDBOX);

        $providerConfig = (array) config("payment.providers.{$provider}", []);

        return new self([
            'provider_url' => (string) ($providerConfig['base_url'] ?? ''),
            'api_key' => (string) ($providerConfig['api_key'] ?? ''),
        ]);
    }

    public function createPayment(CreatePaymentRequest $request): PaymentCheckoutResult
    {
        $apiKey = trim($this->config['api_key'] ?? '');
        $providerUrl = rtrim(trim($this->config['provider_url'] ?? ''), '/');

        if ($apiKey === '') {
            throw PaymentCheckoutUnavailableException::becauseApiKeyIsUnset();
        }

        if ($providerUrl === '') {
            throw PaymentCheckoutUnavailableException::becauseProviderUrlIsUnset();
        }

        $payload = $this->payloadFor($request);

        try {
            $response = Http::withHeaders(['X-Api-Key' => $apiKey])
                ->post($providerUrl.self::CREATE_PAYMENT_PATH, $payload);
        } catch (ConnectionException $e) {
            throw PaymentCheckoutProviderException::becauseProviderUnreachable($e);
        }

        if (! $response->successful()) {
            throw PaymentCheckoutProviderException::forStatus($response->status());
        }

        return $this->resultFrom($response->json());
    }

    /**
     * ADR-0033 §Decision request: `order_id`, `amount`, `currency: "IDR"`,
     * optional `expires_in_hours` (default 24, max 24), optional
     * `success_return_url`/`cancel_return_url`. Optional fields are omitted
     * rather than nulled, so the provider's own defaults apply.
     *
     * @return array<string, string|int>
     */
    private function payloadFor(CreatePaymentRequest $request): array
    {
        $payload = [
            'order_id' => $request->orderId,
            'amount' => $request->amountMinor,
            'currency' => $request->currency,
        ];

        if ($request->successReturnUrl !== null) {
            $payload['success_return_url'] = $request->successReturnUrl;
        }

        if ($request->cancelReturnUrl !== null) {
            $payload['cancel_return_url'] = $request->cancelReturnUrl;
        }

        if ($request->expiresInHours !== null) {
            $payload['expires_in_hours'] = $request->expiresInHours;
        }

        return $payload;
    }

    /**
     * @param  mixed  $body  the decoded response body, whatever the provider sent
     */
    private function resultFrom(mixed $body): PaymentCheckoutResult
    {
        if (! is_array($body)) {
            throw PaymentCheckoutProviderException::becauseMalformedResponse();
        }

        foreach (self::REQUIRED_RESPONSE_FIELDS as $field) {
            if (! array_key_exists($field, $body)) {
                throw PaymentCheckoutProviderException::becauseMalformedResponse();
            }
        }

        $expiresAt = null;

        if (array_key_exists('expires_at', $body)) {
            if (! is_string($body['expires_at'])) {
                throw PaymentCheckoutProviderException::becauseMalformedResponse();
            }

            try {
                $expiresAt = CarbonImmutable::parse($body['expires_at']);
            } catch (Throwable) {
                throw PaymentCheckoutProviderException::becauseMalformedResponse();
            }
        }

        return new PaymentCheckoutResult(
            paymentId: (string) $body['payment_id'],
            orderId: (string) $body['order_id'],
            amountMinor: (int) $body['amount'],
            feeMinor: (int) $body['fee'],
            netAmountMinor: (int) $body['net_amount'],
            paymentLinkUrl: (string) $body['payment_link_url'],
            status: (string) $body['status'],
            expiresAt: $expiresAt,
        );
    }
}
