<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Platform\Audit\Models\AuditEvent;
use App\Platform\Payment\Jobs\ProcessProviderEventJob;
use App\Platform\Payment\Models\ProviderEvent;
use App\Platform\Payment\PaymentAuditActions;
use App\Platform\Payment\PaymentProviders;
use App\Platform\Payment\ProviderEventStatus;
use App\Platform\Payment\WebhookValidator;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * AC5 / AC6 / AC13 at the HTTP boundary.
 *
 * ---------------------------------------------------------------------------
 * Read this before wondering where the happy path is
 * ---------------------------------------------------------------------------
 * There isn't one, and there must not be one yet. Wave 1b ruling 1b-L3-01 made
 * `GuardPaymentSession` deny-only, so no `payment_sessions` row can exist —
 * `PaymentGuardFailClosedTest` proves that from three directions. Every
 * well-formed, correctly signed webhook therefore stops at `REJECTED_SESSION`,
 * which is the fail-closed behaviour the ruling intends: a webhook that cannot
 * be tied to a session this system authorized is not evidence of payment
 * (`AGENTS.md` §Domain and financial invariants).
 *
 * No session fixture is fabricated here and no test-only bypass exists. The
 * session-bound checks (AC13's merchant/`badan_usaha` reconciliation, AC6's
 * amount comparison against the session snapshot) are implemented for real in
 * `WebhookValidator` and are exercised only through the rejection they produce
 * today; they are recorded as NOT TESTED in the Task 3 report.
 *
 * Every secret in this file is a locally generated test string.
 */
final class WebhookReceiverTest extends TestCase
{
    use RefreshDatabase;

    private const string MERCHANT = 'makam-sandbox';

    // Deliberately low-entropy (repeated-character) so no high-entropy-secret
    // scanner mistakes a test fixture for a leaked credential; still valid
    // base64, so the real decode path in `keyFor()` is exercised.
    private const string SECRET = 'whsec_YWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFh';

    private const string ENDPOINT = '/api/payments/webhook/'.self::MERCHANT;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'payment.default' => PaymentProviders::SUMOPOD_SANDBOX,
            'payment.providers.'.PaymentProviders::SUMOPOD_SANDBOX.'.webhook_signing_secrets' => [self::SECRET],
            'payment.providers.'.PaymentProviders::SUMOPOD_SANDBOX.'.webhook_tokens' => [],
            'payment.webhook.allow_shared_token' => false,
            'payment.webhook.merchants' => [self::MERCHANT],
            'payment.webhook.replay_window_seconds' => 300,
        ]);

        // Nothing in the request path may reach the network (AC5: "no async
        // work, no HTTP call, no provider round-trip in the request path").
        Http::preventStrayRequests();
        Http::fake();
    }

    /**
     * ADR-0033 §Decision's webhook envelope.
     */
    private function body(array $overrides = [], array $dataOverrides = []): string
    {
        return json_encode(array_merge([
            'event_type' => 'payment.completed',
            'data' => array_merge([
                'payment_id' => 'pay_'.bin2hex(random_bytes(4)),
                'order_id' => 'INV-2026-0001',
                'amount' => 1_500_000,
                'fee' => 10_800,
                'net_amount' => 1_489_200,
                'status' => 'completed',
                'payment_method' => 'QRIS',
                'completed_at' => '2026-08-09T09:59:00+00:00',
            ], $dataOverrides),
        ], $overrides), JSON_THROW_ON_ERROR);
    }

    private function signature(string $id, string $timestamp, string $body): string
    {
        $key = (string) base64_decode(substr(self::SECRET, strlen('whsec_')), true);

        return 'v1,'.base64_encode(hash_hmac('sha256', "{$id}.{$timestamp}.{$body}", $key, true));
    }

    /**
     * A correctly signed delivery. `$signature`/`$id`/`$timestamp` can be
     * overridden to forge one.
     */
    private function deliver(
        ?string $body = null,
        ?string $signature = null,
        string $id = 'msg_01',
        ?string $timestamp = null,
        string $endpoint = self::ENDPOINT,
        array $extraHeaders = [],
    ) {
        $body ??= $this->body();
        $timestamp ??= (string) CarbonImmutable::now()->getTimestamp();

        // Headers go through `transformHeadersToServerVars()` rather than
        // `withHeaders()`: `call()` — the only test helper that lets a raw body
        // be sent verbatim, which is exactly what a signature is computed over
        // — does not apply the default-header bag.
        $headers = $this->transformHeadersToServerVars(array_merge([
            'Content-Type' => 'application/json',
            'svix-id' => $id,
            'svix-timestamp' => $timestamp,
            'svix-signature' => $signature ?? $this->signature($id, $timestamp, $body),
        ], $extraHeaders));

        return $this->call('POST', $endpoint, [], [], [], $headers, $body);
    }

    private function assertAcknowledged($response): void
    {
        $response->assertOk();
        $response->assertJsonStructure(['status', 'reference']);
        $this->assertSame('received', $response->json('status'));
    }

    private function assertRejectionRecorded(ProviderEventStatus $expected): ProviderEvent
    {
        $event = ProviderEvent::query()->sole();

        $this->assertSame($expected->value, $event->status, 'recorded status');
        $this->assertNotNull($event->rejection_detail, 'a rejection must carry an internal detail');

        $audit = AuditEvent::query()
            ->where('action', PaymentAuditActions::WEBHOOK_REJECTED)
            ->sole();

        $this->assertSame('denied', $audit->outcome);
        $this->assertSame('provider', $audit->actor_role);
        $this->assertStringContainsString($expected->value, (string) ($audit->metadata['note'] ?? ''));

        return $event;
    }

    // -----------------------------------------------------------------
    // AC5 — persist, then ack
    // -----------------------------------------------------------------

    public function test_the_raw_body_is_persisted_verbatim_before_anything_is_validated(): void
    {
        $body = $this->body();

        // Forged: the delivery is rejected, and the row must exist anyway.
        $response = $this->deliver(body: $body, signature: 'v1,'.base64_encode('forged'));

        $this->assertAcknowledged($response);

        $event = ProviderEvent::query()->sole();

        $this->assertSame($body, $event->raw_payload);
        $this->assertSame(hash('sha256', $body), $event->payload_digest);
        $this->assertSame(self::MERCHANT, $event->merchant_ref);
        $this->assertSame('msg_01', $event->provider_event_id);
        $this->assertSame('svix-id', $event->event_id_source);
        $this->assertNotNull($event->received_at);
    }

    public function test_signed_metadata_reverifies_the_stored_raw_evidence(): void
    {
        $this->deliver(id: 'msg_reverify');

        $event = ProviderEvent::query()->sole();
        $this->assertSame('svix-id', $event->event_id_source);
        $this->assertNotNull($event->signature_timestamp);
        $this->assertNotNull($event->signature_header);
        $verification = app(WebhookValidator::class)->verifyStoredEvidence($event, CarbonImmutable::now());

        $this->assertTrue($verification->isVerified(), $verification->outcome->name);
    }

    public function test_complete_signed_header_metadata_is_stored_without_truncation(): void
    {
        $body = $this->body();
        $timestamp = (string) CarbonImmutable::now()->getTimestamp();
        $signature = $this->signature('msg_full_header', $timestamp, $body);
        $header = $signature.' '.$signature;

        $this->assertAcknowledged($this->deliver(
            body: $body,
            id: 'msg_full_header',
            timestamp: $timestamp,
            signature: $header,
        ));

        $event = ProviderEvent::query()->sole();
        $this->assertSame($header, $event->signature_header);
        $this->assertTrue(
            app(WebhookValidator::class)->verifyStoredEvidence($event, CarbonImmutable::now())->isVerified()
        );
    }

    public function test_an_overlong_svix_id_is_rejected_before_persistence_as_an_event_identity(): void
    {
        $id = str_repeat('i', 181);
        $body = $this->body();
        $timestamp = (string) CarbonImmutable::now()->getTimestamp();

        $this->assertAcknowledged($this->deliver(
            body: $body,
            id: $id,
            timestamp: $timestamp,
            signature: $this->signature($id, $timestamp, $body),
        ));

        $event = $this->assertRejectionRecorded(ProviderEventStatus::RejectedPayload);
        $this->assertSame('body-digest', $event->event_id_source);
        $this->assertNull($event->signature_header);
    }

    public function test_an_overlong_svix_signature_is_rejected_before_persisting_signed_metadata(): void
    {
        $body = $this->body();
        $timestamp = (string) CarbonImmutable::now()->getTimestamp();
        $signature = $this->signature('msg_overlong_signature', $timestamp, $body)
            .' '.str_repeat('x', 4_097);

        $this->assertAcknowledged($this->deliver(
            body: $body,
            id: 'msg_overlong_signature',
            timestamp: $timestamp,
            signature: $signature,
        ));

        $event = $this->assertRejectionRecorded(ProviderEventStatus::RejectedPayload);
        $this->assertNull($event->signature_header);
    }

    public function test_the_receiver_does_no_async_work_and_makes_no_provider_call_in_the_request_path(): void
    {
        Queue::fake();

        $startedAt = microtime(true);
        $response = $this->deliver();
        $elapsed = microtime(true) - $startedAt;

        $this->assertAcknowledged($response);

        // `payment-webhook.md` §5's 2-second ack, which the plan adopts over
        // ADR-0033's looser 10-second ceiling. This is a floor-level guard;
        // the real guarantee is structural — see the two assertions below.
        $this->assertLessThan(2.0, $elapsed, 'the acknowledgment must be returned within 2 seconds');

        Http::assertNothingSent();

        // Today's delivery is rejected before dispatch, so nothing is queued.
        // The structural claim being pinned is that the receiver never RUNS
        // the apply work inline.
        Queue::assertNothingPushed();
    }

    // -----------------------------------------------------------------
    // AC6 — validate; record and reject, never silently ignore
    // -----------------------------------------------------------------

    public function test_a_correctly_signed_delivery_with_no_payment_session_is_rejected_and_recorded(): void
    {
        Queue::fake();

        $response = $this->deliver();

        $this->assertAcknowledged($response);
        $this->assertRejectionRecorded(ProviderEventStatus::RejectedSession);

        // The fail-closed consequence that matters: no apply job is dispatched
        // for a webhook that could not be bound to a session.
        Queue::assertNotPushed(ProcessProviderEventJob::class);
    }

    public function test_a_forged_signature_is_recorded_as_rejected_signature_and_still_acknowledged(): void
    {
        $response = $this->deliver(signature: 'v1,'.base64_encode('forged'));

        $this->assertAcknowledged($response);
        $this->assertRejectionRecorded(ProviderEventStatus::RejectedSignature);
    }

    public function test_a_delivery_with_no_credential_at_all_is_rejected(): void
    {
        $body = $this->body();

        $response = $this->withHeaders(['Content-Type' => 'application/json'])
            ->call('POST', self::ENDPOINT, [], [], [], [], $body);

        $this->assertAcknowledged($response);
        $this->assertRejectionRecorded(ProviderEventStatus::RejectedSignature);

        // With no `svix-id` to use as the provider event identity, the body
        // digest stands in — see `ReceiveWebhook::resolveEventIdentity()`.
        $event = ProviderEvent::query()->sole();
        $this->assertSame('body-digest', $event->event_id_source);
        $this->assertSame('sha256:'.hash('sha256', $body), $event->provider_event_id);
    }

    public function test_an_authentic_but_stale_delivery_is_recorded_as_a_replay(): void
    {
        $stale = (string) CarbonImmutable::now()->subSeconds(3_600)->getTimestamp();

        $response = $this->deliver(timestamp: $stale);

        $this->assertAcknowledged($response);
        $this->assertRejectionRecorded(ProviderEventStatus::RejectedReplay);
    }

    public function test_a_delivery_to_a_merchant_this_environment_does_not_serve_is_rejected(): void
    {
        config(['payment.webhook.merchants' => ['some-other-merchant']]);

        $response = $this->deliver();

        $this->assertAcknowledged($response);
        $event = $this->assertRejectionRecorded(ProviderEventStatus::RejectedMerchant);

        // AC13: the endpoint the delivery arrived on is recorded on the row,
        // whatever the outcome.
        $this->assertSame(self::MERCHANT, $event->merchant_ref);
    }

    public function test_an_environment_with_no_configured_merchant_rejects_every_delivery(): void
    {
        config(['payment.webhook.merchants' => []]);

        $this->assertAcknowledged($this->deliver());
        $this->assertRejectionRecorded(ProviderEventStatus::RejectedMerchant);
    }

    public function test_a_non_positive_amount_is_recorded_as_rejected_amount(): void
    {
        $body = $this->body(dataOverrides: ['amount' => 0]);

        $this->assertAcknowledged($this->deliver(body: $body));
        $this->assertRejectionRecorded(ProviderEventStatus::RejectedAmount);
    }

    public function test_a_negative_amount_is_recorded_as_rejected_amount(): void
    {
        $body = $this->body(dataOverrides: ['amount' => -1]);

        $this->assertAcknowledged($this->deliver(body: $body));
        $this->assertRejectionRecorded(ProviderEventStatus::RejectedAmount);
    }

    public function test_a_fractional_json_amount_is_refused_rather_than_rounded(): void
    {
        // Wave 0 ruling 0c: no float on the money path. A JSON number with real
        // fractional content cannot be converted to integer minor units without
        // trusting a binary double, so the envelope refuses it.
        $body = str_replace('"amount":1500000', '"amount":1500000.125', $this->body());

        $this->assertAcknowledged($this->deliver(body: $body));
        $event = $this->assertRejectionRecorded(ProviderEventStatus::RejectedPayload);

        $this->assertStringContainsString('non_integer_amount', (string) $event->rejection_detail);
    }

    public function test_an_exactly_integral_json_amount_is_converted_to_integer_minor_units(): void
    {
        $body = str_replace('"amount":1500000', '"amount":1500000.00', $this->body());

        $this->assertAcknowledged($this->deliver(body: $body));

        $event = ProviderEvent::query()->sole();

        // IDR minor units are 1/100 (config/money.php), so Rp 1.500.000
        // becomes 150 000 000 minor units — an integer, never a float.
        $this->assertSame(150_000_000, $event->amount_minor);
        $this->assertIsInt($event->amount_minor);
    }

    public function test_a_declared_currency_that_is_not_the_configured_currency_is_rejected(): void
    {
        $body = $this->body(dataOverrides: ['currency' => 'USD']);

        $this->assertAcknowledged($this->deliver(body: $body));
        $this->assertRejectionRecorded(ProviderEventStatus::RejectedCurrency);
    }

    public function test_a_malformed_body_is_recorded_as_rejected_payload(): void
    {
        $this->assertAcknowledged($this->deliver(body: '{not json at all'));

        $event = $this->assertRejectionRecorded(ProviderEventStatus::RejectedPayload);

        $this->assertSame('{not json at all', $event->raw_payload);
        $this->assertNull($event->provider_transaction_id);
    }

    public function test_a_body_missing_the_invoice_reference_is_recorded_as_rejected_payload(): void
    {
        $body = json_encode([
            'event_type' => 'payment.completed',
            'data' => ['payment_id' => 'pay_1', 'amount' => 1000],
        ], JSON_THROW_ON_ERROR);

        $this->assertAcknowledged($this->deliver(body: $body));

        $event = $this->assertRejectionRecorded(ProviderEventStatus::RejectedPayload);

        $this->assertStringContainsString('missing_data_order_id', (string) $event->rejection_detail);
    }

    // -----------------------------------------------------------------
    // AC7 — idempotency
    // -----------------------------------------------------------------

    public function test_a_duplicate_delivery_yields_exactly_one_row_and_a_success_acknowledgment(): void
    {
        $body = $this->body();
        $timestamp = (string) CarbonImmutable::now()->getTimestamp();

        $first = $this->deliver(body: $body, id: 'msg_dup', timestamp: $timestamp);
        $second = $this->deliver(body: $body, id: 'msg_dup', timestamp: $timestamp);

        $this->assertAcknowledged($first);
        $this->assertAcknowledged($second);

        $this->assertSame(1, ProviderEvent::query()->count(), 'a redelivery must not write a second row');

        // payment-webhook.md §Idempotency: "returns a success acknowledgment
        // and the original processing reference".
        $this->assertSame($first->json('reference'), $second->json('reference'));

        // The only durable record of the second arrival — the row it would
        // have written is exactly what the unique guard refused.
        $this->assertSame(
            1,
            AuditEvent::query()->where('action', PaymentAuditActions::WEBHOOK_DUPLICATE)->count()
        );
    }

    public function test_a_redelivery_does_not_rewrite_the_original_rows_recorded_status(): void
    {
        $body = $this->body();
        $timestamp = (string) CarbonImmutable::now()->getTimestamp();

        $this->deliver(body: $body, id: 'msg_dup2', timestamp: $timestamp);

        $before = ProviderEvent::query()->sole();

        $this->deliver(body: $body, id: 'msg_dup2', timestamp: $timestamp);

        $after = ProviderEvent::query()->sole();

        $this->assertSame($before->status, $after->status);
        $this->assertSame($before->raw_payload, $after->raw_payload);
        $this->assertEquals($before->updated_at, $after->updated_at);
    }

    public function test_a_duplicate_delivery_recovers_a_row_stuck_before_validation(): void
    {
        $body = $this->body();
        $timestamp = (string) CarbonImmutable::now()->getTimestamp();

        ProviderEvent::create([
            'provider' => PaymentProviders::SUMOPOD_SANDBOX,
            'provider_event_id' => 'msg_stuck',
            'event_id_source' => 'svix-id',
            'provider_transaction_id' => 'pay_stuck',
            'invoice_reference' => 'INV-2026-0001',
            'event_type' => 'payment.completed',
            'merchant_ref' => self::MERCHANT,
            'amount_minor' => 150_000_000,
            'declared_currency' => null,
            'raw_payload' => $body,
            'payload_digest' => hash('sha256', $body),
            'signature_timestamp' => $timestamp,
            'signature_header' => $this->signature('msg_stuck', $timestamp, $body),
            'status' => ProviderEventStatus::Received->value,
            'received_at' => CarbonImmutable::now(),
        ]);

        $response = $this->deliver(body: $body, id: 'msg_stuck', timestamp: $timestamp);

        $this->assertAcknowledged($response);
        $event = ProviderEvent::query()->sole();
        $this->assertSame(ProviderEventStatus::RejectedSession->value, $event->status);
        $this->assertSame(0, AuditEvent::query()->where('action', PaymentAuditActions::WEBHOOK_DUPLICATE)->count());
        $this->assertSame(1, AuditEvent::query()->where('action', PaymentAuditActions::WEBHOOK_REJECTED)->count());
    }

    public function test_a_same_id_with_a_different_body_cannot_recover_the_original_row(): void
    {
        $originalBody = $this->body(dataOverrides: ['payment_id' => 'pay_original']);
        $retryBody = $this->body(dataOverrides: ['payment_id' => 'pay_tampered']);
        $timestamp = (string) CarbonImmutable::now()->getTimestamp();

        ProviderEvent::create([
            'provider' => PaymentProviders::SUMOPOD_SANDBOX,
            'provider_event_id' => 'msg_digest_guard',
            'event_id_source' => 'svix-id',
            'provider_transaction_id' => 'pay_original',
            'invoice_reference' => 'INV-2026-0001',
            'event_type' => 'payment.completed',
            'merchant_ref' => self::MERCHANT,
            'amount_minor' => 150_000_000,
            'raw_payload' => $originalBody,
            'payload_digest' => hash('sha256', $originalBody),
            'signature_timestamp' => $timestamp,
            'signature_header' => $this->signature('msg_digest_guard', $timestamp, $originalBody),
            'status' => ProviderEventStatus::Received->value,
            'received_at' => CarbonImmutable::now(),
        ]);

        $this->assertAcknowledged($this->deliver(
            body: $retryBody,
            id: 'msg_digest_guard',
            timestamp: $timestamp,
        ));

        $event = ProviderEvent::query()->sole();
        $this->assertSame(ProviderEventStatus::Received->value, $event->status);
        $this->assertSame(0, AuditEvent::query()->where('action', PaymentAuditActions::WEBHOOK_DUPLICATE)->count());
        $audit = AuditEvent::query()->where('action', PaymentAuditActions::WEBHOOK_REJECTED)->sole();
        $this->assertStringContainsString('payload digest mismatch', (string) ($audit->metadata['note'] ?? ''));
    }

    public function test_a_secondary_collision_with_a_different_body_cannot_recover_the_original_row(): void
    {
        $originalBody = $this->body(dataOverrides: [
            'payment_id' => 'pay_secondary',
            'order_id' => 'INV-secondary',
        ]);
        $retryBody = $this->body(dataOverrides: [
            'payment_id' => 'pay_secondary',
            'order_id' => 'INV-secondary',
            'amount' => 1_500_001,
        ]);
        $originalTimestamp = (string) CarbonImmutable::now()->getTimestamp();

        ProviderEvent::create([
            'provider' => PaymentProviders::SUMOPOD_SANDBOX,
            'provider_event_id' => 'msg_secondary_original',
            'event_id_source' => 'svix-id',
            'provider_transaction_id' => 'pay_secondary',
            'invoice_reference' => 'INV-secondary',
            'event_type' => 'payment.completed',
            'merchant_ref' => self::MERCHANT,
            'amount_minor' => 150_000_000,
            'raw_payload' => $originalBody,
            'payload_digest' => hash('sha256', $originalBody),
            'signature_timestamp' => $originalTimestamp,
            'signature_header' => $this->signature('msg_secondary_original', $originalTimestamp, $originalBody),
            'status' => ProviderEventStatus::Received->value,
            'received_at' => CarbonImmutable::now(),
        ]);

        $this->assertAcknowledged($this->deliver(
            body: $retryBody,
            id: 'msg_secondary_retry',
        ));

        $event = ProviderEvent::query()->sole();
        $this->assertSame(ProviderEventStatus::Received->value, $event->status);
        $this->assertSame(0, AuditEvent::query()->where('action', PaymentAuditActions::WEBHOOK_DUPLICATE)->count());
        $audit = AuditEvent::query()->where('action', PaymentAuditActions::WEBHOOK_REJECTED)->sole();
        $this->assertStringContainsString('payload digest mismatch', (string) ($audit->metadata['note'] ?? ''));
    }

    public function test_duplicate_recovery_rejects_an_overlong_signed_header_before_validation(): void
    {
        $body = $this->body(dataOverrides: ['payment_id' => 'pay_overlong_retry']);
        $timestamp = (string) CarbonImmutable::now()->getTimestamp();
        $validSignature = $this->signature('msg_overlong_retry', $timestamp, $body);

        ProviderEvent::create([
            'provider' => PaymentProviders::SUMOPOD_SANDBOX,
            'provider_event_id' => 'msg_overlong_retry',
            'event_id_source' => 'svix-id',
            'provider_transaction_id' => 'pay_overlong_retry',
            'invoice_reference' => 'INV-2026-0001',
            'event_type' => 'payment.completed',
            'merchant_ref' => self::MERCHANT,
            'amount_minor' => 150_000_000,
            'raw_payload' => $body,
            'payload_digest' => hash('sha256', $body),
            'signature_timestamp' => $timestamp,
            'signature_header' => $validSignature,
            'status' => ProviderEventStatus::Received->value,
            'received_at' => CarbonImmutable::now(),
        ]);

        $this->assertAcknowledged($this->deliver(
            body: $body,
            id: 'msg_overlong_retry',
            timestamp: $timestamp,
            signature: $validSignature.' '.str_repeat('x', 4_097),
        ));

        $event = ProviderEvent::query()->sole();
        $this->assertSame(ProviderEventStatus::Received->value, $event->status);
        $audit = AuditEvent::query()->where('action', PaymentAuditActions::WEBHOOK_REJECTED)->sole();
        $this->assertStringContainsString('svix-signature header is too long', (string) ($audit->metadata['note'] ?? ''));
    }

    public function test_duplicate_recovery_rejects_a_malformed_signed_header_before_validation(): void
    {
        $body = $this->body(dataOverrides: ['payment_id' => 'pay_malformed_retry']);
        $timestamp = (string) CarbonImmutable::now()->getTimestamp();
        $validSignature = $this->signature('msg_malformed_retry', $timestamp, $body);

        ProviderEvent::create([
            'provider' => PaymentProviders::SUMOPOD_SANDBOX,
            'provider_event_id' => 'msg_malformed_retry',
            'event_id_source' => 'svix-id',
            'provider_transaction_id' => 'pay_malformed_retry',
            'invoice_reference' => 'INV-2026-0001',
            'event_type' => 'payment.completed',
            'merchant_ref' => self::MERCHANT,
            'amount_minor' => 150_000_000,
            'raw_payload' => $body,
            'payload_digest' => hash('sha256', $body),
            'signature_timestamp' => $timestamp,
            'signature_header' => $validSignature,
            'status' => ProviderEventStatus::Received->value,
            'received_at' => CarbonImmutable::now(),
        ]);

        $this->assertAcknowledged($this->deliver(
            body: $body,
            id: 'msg_malformed_retry',
            timestamp: $timestamp,
            signature: $validSignature.' malformed-entry',
        ));

        $event = ProviderEvent::query()->sole();
        $this->assertSame(ProviderEventStatus::Received->value, $event->status);
        $audit = AuditEvent::query()->where('action', PaymentAuditActions::WEBHOOK_REJECTED)->sole();
        $this->assertStringContainsString('svix-signature header is malformed', (string) ($audit->metadata['note'] ?? ''));
    }

    public function test_envelope_field_lengths_are_rejected_before_persistence(): void
    {
        $body = $this->body(dataOverrides: ['payment_id' => str_repeat('x', 129)]);

        $this->assertAcknowledged($this->deliver(body: $body));

        $event = $this->assertRejectionRecorded(ProviderEventStatus::RejectedPayload);
        $this->assertStringContainsString('payment_id', (string) $event->rejection_detail);
        $this->assertNull($event->provider_transaction_id);
    }

    public function test_rejection_status_and_audit_roll_back_together_if_audit_fails(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION fail_audit_insert() RETURNS trigger AS $$
                BEGIN
                    RAISE EXCEPTION 'audit insert failed';
                END;
                $$ LANGUAGE plpgsql
                SQL);
            DB::unprepared(
                'CREATE TRIGGER fail_audit_insert BEFORE INSERT ON audit_events '
                .'FOR EACH ROW EXECUTE FUNCTION fail_audit_insert()'
            );
        } else {
            DB::statement(
                'CREATE TRIGGER fail_audit_insert BEFORE INSERT ON audit_events '
                ."BEGIN SELECT RAISE(ABORT, 'audit insert failed'); END"
            );
        }
        $this->withoutExceptionHandling();

        $thrown = false;

        try {
            $this->deliver(signature: 'v1,'.base64_encode('forged'));
        } catch (QueryException) {
            $thrown = true;
        }

        $this->assertSame(0, ProviderEvent::query()->count());
        $this->assertSame(0, AuditEvent::query()->count());
        $this->assertTrue($thrown);
    }

    // -----------------------------------------------------------------
    // The endpoint itself — throttle, bounds, and the absence of an oracle
    // -----------------------------------------------------------------

    public function test_the_endpoint_is_rate_limited(): void
    {
        config([
            'payment.webhook.throttle.max_attempts' => 3,
            'payment.webhook.throttle.decay_seconds' => 60,
        ]);

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $this->deliver(id: "msg_rate_{$attempt}")->assertOk();
        }

        $blocked = $this->deliver(id: 'msg_rate_4');

        $blocked->assertStatus(429);

        // The throttle must refuse BEFORE the durable row is written —
        // otherwise it would not bound the write amplification it exists to
        // bound.
        $this->assertSame(3, ProviderEvent::query()->count());
    }

    public function test_the_rate_limit_is_scoped_per_merchant_so_one_merchant_cannot_starve_another(): void
    {
        config([
            'payment.webhook.throttle.max_attempts' => 2,
            'payment.webhook.throttle.decay_seconds' => 60,
            'payment.webhook.merchants' => [self::MERCHANT, 'makam-other'],
        ]);

        $this->deliver(id: 'msg_a1')->assertOk();
        $this->deliver(id: 'msg_a2')->assertOk();
        $this->deliver(id: 'msg_a3')->assertStatus(429);

        $this->deliver(id: 'msg_b1', endpoint: '/api/payments/webhook/makam-other')->assertOk();
    }

    public function test_a_hostile_merchant_segment_is_a_404_that_stores_nothing(): void
    {
        $this->deliver(endpoint: '/api/payments/webhook/..%2Fetc%2Fpasswd')->assertNotFound();

        $this->assertSame(0, ProviderEvent::query()->count());
    }

    public function test_an_oversized_body_is_recorded_as_a_bounded_rejected_payload_and_acked(): void
    {
        config(['payment.webhook.max_body_bytes' => 512]);

        $body = $this->body(dataOverrides: ['payment_method' => str_repeat('A', 2_000)]);

        $this->deliver(body: $body)->assertOk();

        $event = $this->assertRejectionRecorded(ProviderEventStatus::RejectedPayload);
        $this->assertLessThanOrEqual(512, strlen((string) $event->raw_payload));
        $this->assertSame(hash('sha256', $body), $event->payload_digest);

        // The stored evidence is a bounded marker, not a silently dropped body.
        $this->assertStringContainsString('oversized', (string) $event->raw_payload);
    }

    public function test_the_response_body_is_identical_for_every_rejection_reason(): void
    {
        // A response that varied with the reason would let an unauthenticated
        // caller enumerate merchants, transactions and amounts one request at
        // a time.
        $forged = $this->deliver(id: 'msg_o1', signature: 'v1,'.base64_encode('forged'));

        config(['payment.webhook.merchants' => []]);
        $unknownMerchant = $this->deliver(id: 'msg_o2');

        config(['payment.webhook.merchants' => [self::MERCHANT]]);
        $noSession = $this->deliver(id: 'msg_o3');

        foreach ([$forged, $unknownMerchant, $noSession] as $response) {
            $response->assertOk();
            $this->assertSame('received', $response->json('status'));
            $this->assertSame(['status', 'reference'], array_keys($response->json()));
        }
    }

    public function test_the_endpoint_accepts_a_post_without_a_csrf_token(): void
    {
        // A provider cannot hold a CSRF token. The route is registered in the
        // `api` group precisely so no session/CSRF middleware applies; this
        // pins that it stayed there.
        $this->deliver()->assertOk();
    }

    public function test_every_delivery_carries_the_request_boundary_correlation_id(): void
    {
        $this->deliver(extraHeaders: ['X-Correlation-Id' => 'trace-abc-123'])->assertOk();

        $event = ProviderEvent::query()->sole();

        $this->assertSame('trace-abc-123', $event->correlation_id);

        $audit = AuditEvent::query()->where('action', PaymentAuditActions::WEBHOOK_REJECTED)->sole();
        $this->assertSame('trace-abc-123', $audit->correlation_id);
    }
}
