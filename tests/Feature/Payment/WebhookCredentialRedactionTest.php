<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Platform\Payment\Http\Middleware\RedactProviderPayload;
use App\Platform\Payment\Http\WebhookCredentials;
use App\Platform\Payment\PaymentProviders;
use App\Platform\Payment\Providers\SignatureOutcome;
use App\Platform\Payment\Providers\SumoPodWebhookSignature;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * AC14: "THE SYSTEM SHALL source provider credentials from secret management,
 * scoped per environment. THE SYSTEM SHALL NOT let credentials appear in logs
 * or error trackers." `AGENTS.md` §Observability: "Never place restricted data
 * in logs, Pulse, Horizon tags, or error trackers."
 *
 * Every credential-shaped value here is a locally invented test string. The
 * real values live only in the host env file (ADR-0033 §Credential) and this
 * repository must never contain one — which is itself part of what AC14 asks,
 * so a fixture carrying a real secret would be a violation of the requirement
 * it was written to test.
 */
final class WebhookCredentialRedactionTest extends TestCase
{
    use RefreshDatabase;

    private const string MERCHANT = 'makam-sandbox';

    private const string SECRET = 'whsec_dGVzdC1zZWNyZXQtbm90LWEtcmVhbC1vbmU=';

    private const string TOKEN = 'whtok_test-shared-token-value';

    private const string API_KEY = 'test-api-key-value-for-assertions-only';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'payment.default' => PaymentProviders::SUMOPOD_SANDBOX,
            'payment.providers.'.PaymentProviders::SUMOPOD_SANDBOX.'.api_key' => self::API_KEY,
            'payment.providers.'.PaymentProviders::SUMOPOD_SANDBOX.'.webhook_signing_secrets' => [self::SECRET],
            'payment.providers.'.PaymentProviders::SUMOPOD_SANDBOX.'.webhook_tokens' => [self::TOKEN],
            'payment.webhook.allow_shared_token' => true,
            'payment.webhook.merchants' => [self::MERCHANT],
        ]);

        Http::preventStrayRequests();
        Http::fake();
    }

    // -----------------------------------------------------------------
    // The middleware removes rather than filters
    // -----------------------------------------------------------------

    public function test_credential_headers_are_removed_from_the_request_before_any_handler_runs(): void
    {
        $request = Request::create('/api/payments/webhook/'.self::MERCHANT, 'POST', server: [
            'HTTP_SVIX_SIGNATURE' => 'v1,'.base64_encode('signature-bytes'),
            'HTTP_X_WEBHOOK_TOKEN' => self::TOKEN,
            'HTTP_X_API_KEY' => self::API_KEY,
            'HTTP_AUTHORIZATION' => 'Bearer '.self::API_KEY,
            'HTTP_SVIX_ID' => 'msg_1',
            'HTTP_SVIX_TIMESTAMP' => '1786000000',
        ]);

        $middleware = $this->app->make(RedactProviderPayload::class);

        $seen = null;

        $middleware->handle($request, function (Request $passed) use (&$seen): Response {
            $seen = $passed;

            return new Response;
        });

        foreach (RedactProviderPayload::CREDENTIAL_HEADERS as $header) {
            $this->assertNull($seen->header($header), "[{$header}] survived the middleware");
        }

        // Symfony rebuilds header values from the server bag, so the server bag
        // has to be scrubbed too or the value stays reachable there.
        $dumped = var_export($seen->headers->all(), true).var_export($seen->server->all(), true);

        $this->assertStringNotContainsString(self::TOKEN, $dumped);
        $this->assertStringNotContainsString(self::API_KEY, $dumped);
        $this->assertStringNotContainsString('signature-bytes', $dumped);

        // The signature-covered, non-secret parts of the Svix envelope must
        // survive — the verifier needs them.
        $this->assertSame('msg_1', $seen->header('svix-id'));
        $this->assertSame('1786000000', $seen->header('svix-timestamp'));
    }

    public function test_the_credentials_object_reports_presence_never_value(): void
    {
        $credentials = new WebhookCredentials(
            svixSignature: 'v1,'.base64_encode('signature-bytes'),
            sharedToken: self::TOKEN,
        );

        $exposures = [
            json_encode($credentials, JSON_THROW_ON_ERROR),
            serialize($credentials),
            var_export($credentials, true),
            (string) $credentials,
            var_export($credentials->__debugInfo(), true),
            var_export($credentials->toArray(), true),
            print_r($credentials->jsonSerialize(), true),
        ];

        foreach ($exposures as $index => $exposure) {
            $this->assertStringNotContainsString(self::TOKEN, $exposure, "exposure #{$index}");
            $this->assertStringNotContainsString('signature-bytes', $exposure, "exposure #{$index}");
        }

        foreach ([$exposures[0], $exposures[1], $exposures[3], $exposures[4], $exposures[5], $exposures[6]] as $index => $exposure) {
            $this->assertStringContainsString('[REDACTED]', $exposure, "redacted exposure #{$index}");
        }

        // Only an explicit property read returns the value — the one thing the
        // signature verifier does.
        $this->assertSame([], get_object_vars($credentials));
        $this->assertSame(self::TOKEN, $credentials->sharedToken());
    }

    public function test_token_only_verification_requires_a_freshness_signal(): void
    {
        $verifier = new SumoPodWebhookSignature(
            signingSecrets: [],
            replayWindowSeconds: 300,
        );

        $withoutTimestamp = $verifier->verify(
            credentials: new WebhookCredentials(sharedToken: self::TOKEN),
            svixId: null,
            svixTimestamp: null,
            rawBody: '{}',
            now: CarbonImmutable::now(),
        );

        $this->assertSame(SignatureOutcome::MechanismUnavailable, $withoutTimestamp->outcome);

        $stale = $verifier->verify(
            credentials: new WebhookCredentials(sharedToken: self::TOKEN),
            svixId: null,
            svixTimestamp: (string) CarbonImmutable::now()->subHour()->getTimestamp(),
            rawBody: '{}',
            now: CarbonImmutable::now(),
        );

        $this->assertSame(SignatureOutcome::MechanismUnavailable, $stale->outcome);

        $withTimestamp = $verifier->verify(
            credentials: new WebhookCredentials(sharedToken: self::TOKEN),
            svixId: null,
            svixTimestamp: (string) CarbonImmutable::now()->getTimestamp(),
            rawBody: '{}',
            now: CarbonImmutable::now(),
        );

        $this->assertSame(SignatureOutcome::MechanismUnavailable, $withTimestamp->outcome);
    }

    public function test_the_middleware_replaces_request_body_with_a_redacted_error_safe_representation(): void
    {
        $rawBody = json_encode([
            'event_type' => 'payment.completed',
            'data' => ['order_id' => 'INV-1', 'webhook_token' => self::TOKEN],
        ], JSON_THROW_ON_ERROR);
        $request = Request::create('/api/payments/webhook/'.self::MERCHANT, 'POST', server: [
            'CONTENT_TYPE' => 'application/json',
        ], content: $rawBody);

        $middleware = $this->app->make(RedactProviderPayload::class);
        $seen = null;

        $middleware->handle($request, function (Request $passed) use (&$seen): Response {
            $seen = $passed;

            return new Response;
        });

        $this->assertSame($rawBody, $seen->attributes->get('payment.webhook.raw_body')->value());
        $this->assertStringNotContainsString(self::TOKEN, $seen->getContent());
        $this->assertStringContainsString(RedactProviderPayload::MASK, $seen->getContent());
    }

    public function test_redact_masks_credential_shaped_keys_at_any_depth(): void
    {
        $redacted = RedactProviderPayload::redact([
            'event_type' => 'payment.completed',
            'merchant_api_key' => self::API_KEY,
            'data' => [
                'order_id' => 'INV-1',
                'signature' => 'v1,abc',
                'nested' => ['webhook_secret' => self::SECRET],
            ],
        ]);

        $flattened = json_encode($redacted, JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString(self::API_KEY, $flattened);
        $this->assertStringNotContainsString(self::SECRET, $flattened);
        $this->assertStringNotContainsString('v1,abc', $flattened);

        // Non-sensitive keys are untouched: a masker that swallowed everything
        // would make the log it protects useless.
        $this->assertSame('payment.completed', $redacted['event_type']);
        $this->assertSame('INV-1', $redacted['data']['order_id']);
    }

    // -----------------------------------------------------------------
    // End to end: nothing a real delivery touches carries a credential
    // -----------------------------------------------------------------

    public function test_no_credential_reaches_a_log_a_stored_row_an_audit_row_or_the_response(): void
    {
        /** @var list<string> $logged */
        $logged = [];

        Event::listen(function (MessageLogged $message) use (&$logged): void {
            $logged[] = $message->message.' '.json_encode($message->context);
        });

        $body = json_encode([
            'event_type' => 'payment.completed',
            'data' => [
                'payment_id' => 'pay_1',
                'order_id' => 'INV-2026-0002',
                'amount' => 1_500_000,
                'completed_at' => '2026-08-09T09:59:00+00:00',
            ],
        ], JSON_THROW_ON_ERROR);

        $timestamp = (string) CarbonImmutable::now()->getTimestamp();
        $key = (string) base64_decode(substr(self::SECRET, strlen('whsec_')), true);
        $signature = 'v1,'.base64_encode(hash_hmac('sha256', "msg_r1.{$timestamp}.{$body}", $key, true));

        $response = $this->call(
            'POST',
            '/api/payments/webhook/'.self::MERCHANT,
            [], [], [],
            $this->transformHeadersToServerVars([
                'Content-Type' => 'application/json',
                'svix-id' => 'msg_r1',
                'svix-timestamp' => $timestamp,
                'svix-signature' => $signature,
                'X-Webhook-Token' => self::TOKEN,
                'X-Api-Key' => self::API_KEY,
            ]),
            $body,
        );

        $response->assertOk();

        // Everything this request wrote or returned, in one haystack: every
        // column of every row in both tables the receiver touches (raw, so
        // `raw_payload` is inspected as the ciphertext actually stored), the
        // response body and headers, and every log line emitted.
        $haystack = implode("\n", [
            json_encode(DB::table('provider_events')->get(), JSON_THROW_ON_ERROR),
            json_encode(DB::table('audit_events')->get(), JSON_THROW_ON_ERROR),
            $response->getContent(),
            json_encode($response->headers->all(), JSON_THROW_ON_ERROR),
            implode("\n", $logged),
        ]);

        foreach ([self::SECRET, self::TOKEN, self::API_KEY, $signature] as $credential) {
            $this->assertStringNotContainsString(
                $credential,
                $haystack,
                'a credential reached a log, a stored row, an audit row, or the response'
            );
        }

        // Only the mechanism NAME is recorded — enough to tell later which
        // path a delivery took, and nothing more.
        $this->assertSame('svix', DB::table('provider_events')->value('signature_mechanism'));
    }

    public function test_the_module_reads_credentials_only_through_config(): void
    {
        // `env()` returns null once `config:cache` has run, so a credential
        // read anywhere but `config/payment.php` is a production-only null.
        // This is a structural assertion, not a behavioural one — it is the
        // only kind that catches the mistake before it ships.
        $offenders = [];

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(app_path('Platform/Payment'))
        );

        foreach ($files as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            if (preg_match('/(?<![\w$>])env\s*\(/', (string) file_get_contents($file->getPathname())) === 1) {
                $offenders[] = $file->getPathname();
            }
        }

        $this->assertSame([], $offenders, 'env() must not be called outside config/payment.php');
    }
}
