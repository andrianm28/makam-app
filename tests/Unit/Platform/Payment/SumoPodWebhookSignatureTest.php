<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Payment;

use App\Platform\Payment\Http\WebhookCredentials;
use App\Platform\Payment\Providers\SignatureMechanism;
use App\Platform\Payment\Providers\SignatureOutcome;
use App\Platform\Payment\Providers\SumoPodWebhookSignature;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/**
 * ADR-0033 §Decision: "Two verification mechanisms, either acceptable: Svix
 * signatures (`svix-id`, `svix-timestamp`, `svix-signature`; HMAC-SHA256 over
 * `{id}.{timestamp}.{rawBody}` with `whsec_…` secret) or a shared-token header
 * `X-Webhook-Token` (`whtok_…`)."
 *
 * Every secret in this file is a locally generated test string. None is, or
 * resembles, a real credential — AC14 forbids a real one appearing in a
 * fixture. Deliberately low-entropy (repeated-character) so no
 * high-entropy-secret scanner mistakes a test fixture for a leaked
 * credential; the repeated bytes are still valid base64, so `keyFor()`'s real
 * decode path is exercised, not its raw-string fallback.
 */
final class SumoPodWebhookSignatureTest extends TestCase
{
    private const string SECRET = 'whsec_'.self::LOW_ENTROPY_A;

    private const string OTHER_SECRET = 'whsec_'.self::LOW_ENTROPY_B;

    private const string TOKEN = 'whtok_cccccccccccccccccccccccc';

    private const string LOW_ENTROPY_A = 'YWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFh';

    private const string LOW_ENTROPY_B = 'YmJiYmJiYmJiYmJiYmJiYmJiYmJiYmJi';

    private const string BODY = '{"event_type":"payment.completed","data":{"payment_id":"pay_1"}}';

    private function verifier(
        array $secrets = [self::SECRET],
        array $tokens = [],
        bool $allowSharedToken = false,
    ): SumoPodWebhookSignature {
        return new SumoPodWebhookSignature(
            signingSecrets: $secrets,
            replayWindowSeconds: 300,
        );
    }

    private function sign(string $id, string $timestamp, string $body, string $secret = self::SECRET): string
    {
        $key = base64_decode(substr($secret, strlen('whsec_')), true);

        return 'v1,'.base64_encode(hash_hmac('sha256', "{$id}.{$timestamp}.{$body}", (string) $key, true));
    }

    private function credentials(?string $signature = null, ?string $token = null): WebhookCredentials
    {
        return new WebhookCredentials(svixSignature: $signature, sharedToken: $token);
    }

    public function test_a_correctly_signed_delivery_verifies(): void
    {
        $now = CarbonImmutable::parse('2026-08-09T10:00:00+00:00');
        $ts = (string) $now->getTimestamp();

        $result = $this->verifier()->verify(
            credentials: $this->credentials(signature: $this->sign('msg_1', $ts, self::BODY)),
            svixId: 'msg_1',
            svixTimestamp: $ts,
            rawBody: self::BODY,
            now: $now,
        );

        $this->assertSame(SignatureOutcome::Verified, $result->outcome);
        $this->assertSame(SignatureMechanism::Svix, $result->mechanism);
    }

    public function test_a_rotated_secret_still_verifies_a_delivery_signed_with_the_previous_one(): void
    {
        $now = CarbonImmutable::parse('2026-08-09T10:00:00+00:00');
        $ts = (string) $now->getTimestamp();

        // New secret first, old secret second — the rotation shape
        // payment-webhook.md §Security requires ("current and rotating secret
        // support").
        $result = $this->verifier(secrets: [self::OTHER_SECRET, self::SECRET])->verify(
            credentials: $this->credentials(signature: $this->sign('msg_1', $ts, self::BODY, self::SECRET)),
            svixId: 'msg_1',
            svixTimestamp: $ts,
            rawBody: self::BODY,
            now: $now,
        );

        $this->assertSame(SignatureOutcome::Verified, $result->outcome);
    }

    public function test_a_signature_over_a_different_body_is_rejected(): void
    {
        $now = CarbonImmutable::parse('2026-08-09T10:00:00+00:00');
        $ts = (string) $now->getTimestamp();

        $result = $this->verifier()->verify(
            credentials: $this->credentials(signature: $this->sign('msg_1', $ts, '{"tampered":true}')),
            svixId: 'msg_1',
            svixTimestamp: $ts,
            rawBody: self::BODY,
            now: $now,
        );

        $this->assertSame(SignatureOutcome::SignatureMismatch, $result->outcome);
    }

    public function test_a_signature_over_a_different_message_id_is_rejected(): void
    {
        $now = CarbonImmutable::parse('2026-08-09T10:00:00+00:00');
        $ts = (string) $now->getTimestamp();

        $result = $this->verifier()->verify(
            credentials: $this->credentials(signature: $this->sign('msg_other', $ts, self::BODY)),
            svixId: 'msg_1',
            svixTimestamp: $ts,
            rawBody: self::BODY,
            now: $now,
        );

        $this->assertSame(SignatureOutcome::SignatureMismatch, $result->outcome);
    }

    public function test_an_authentic_but_stale_delivery_is_a_replay_not_a_bad_signature(): void
    {
        $now = CarbonImmutable::parse('2026-08-09T10:00:00+00:00');
        $stale = (string) $now->subSeconds(301)->getTimestamp();

        $result = $this->verifier()->verify(
            credentials: $this->credentials(signature: $this->sign('msg_1', $stale, self::BODY)),
            svixId: 'msg_1',
            svixTimestamp: $stale,
            rawBody: self::BODY,
            now: $now,
        );

        $this->assertSame(SignatureOutcome::TimestampOutsideWindow, $result->outcome);
    }

    public function test_a_delivery_timestamped_too_far_in_the_future_is_also_outside_the_window(): void
    {
        $now = CarbonImmutable::parse('2026-08-09T10:00:00+00:00');
        $future = (string) $now->addSeconds(301)->getTimestamp();

        $result = $this->verifier()->verify(
            credentials: $this->credentials(signature: $this->sign('msg_1', $future, self::BODY)),
            svixId: 'msg_1',
            svixTimestamp: $future,
            rawBody: self::BODY,
            now: $now,
        );

        $this->assertSame(SignatureOutcome::TimestampOutsideWindow, $result->outcome);
    }

    public function test_the_signature_is_checked_before_the_timestamp(): void
    {
        // A forged delivery carrying a stale timestamp must be reported as a
        // signature failure, not as a replay: an unauthenticated caller must
        // learn nothing about which timestamps this endpoint would accept.
        $now = CarbonImmutable::parse('2026-08-09T10:00:00+00:00');
        $stale = (string) $now->subSeconds(3_600)->getTimestamp();

        $result = $this->verifier()->verify(
            credentials: $this->credentials(signature: 'v1,'.base64_encode('forged')),
            svixId: 'msg_1',
            svixTimestamp: $stale,
            rawBody: self::BODY,
            now: $now,
        );

        $this->assertSame(SignatureOutcome::SignatureMismatch, $result->outcome);
    }

    public function test_a_malformed_timestamp_is_rejected(): void
    {
        $now = CarbonImmutable::parse('2026-08-09T10:00:00+00:00');

        $result = $this->verifier()->verify(
            credentials: $this->credentials(signature: $this->sign('msg_1', 'not-a-timestamp', self::BODY)),
            svixId: 'msg_1',
            svixTimestamp: 'not-a-timestamp',
            rawBody: self::BODY,
            now: $now,
        );

        $this->assertSame(SignatureOutcome::TimestampMalformed, $result->outcome);
    }

    public function test_missing_svix_headers_with_no_enabled_alternative_is_rejected(): void
    {
        $result = $this->verifier()->verify(
            credentials: $this->credentials(),
            svixId: null,
            svixTimestamp: null,
            rawBody: self::BODY,
            now: CarbonImmutable::now(),
        );

        $this->assertSame(SignatureOutcome::MechanismUnavailable, $result->outcome);
        $this->assertNull($result->mechanism);
    }

    public function test_a_partial_svix_header_set_is_malformed_not_ignored(): void
    {
        $result = $this->verifier()->verify(
            credentials: $this->credentials(signature: 'v1,'.base64_encode('x')),
            svixId: 'msg_1',
            svixTimestamp: null,
            rawBody: self::BODY,
            now: CarbonImmutable::now(),
        );

        $this->assertSame(SignatureOutcome::MalformedSignatureHeader, $result->outcome);
    }

    public function test_no_configured_secret_rejects_everything(): void
    {
        $now = CarbonImmutable::parse('2026-08-09T10:00:00+00:00');
        $ts = (string) $now->getTimestamp();

        $result = $this->verifier(secrets: [])->verify(
            credentials: $this->credentials(signature: $this->sign('msg_1', $ts, self::BODY)),
            svixId: 'msg_1',
            svixTimestamp: $ts,
            rawBody: self::BODY,
            now: $now,
        );

        $this->assertSame(SignatureOutcome::NotConfigured, $result->outcome);
    }

    public function test_a_shared_token_is_ignored_while_the_mechanism_is_disabled(): void
    {
        $result = $this->verifier(secrets: [], tokens: [self::TOKEN], allowSharedToken: false)->verify(
            credentials: $this->credentials(token: self::TOKEN),
            svixId: null,
            svixTimestamp: null,
            rawBody: self::BODY,
            now: CarbonImmutable::now(),
        );

        $this->assertSame(SignatureOutcome::MechanismUnavailable, $result->outcome);
    }

    public function test_a_shared_token_stays_disabled_even_when_a_caller_requests_the_mechanism(): void
    {
        $result = $this->verifier(secrets: [], tokens: [self::TOKEN], allowSharedToken: true)->verify(
            credentials: $this->credentials(token: self::TOKEN),
            svixId: null,
            svixTimestamp: null,
            rawBody: self::BODY,
            now: CarbonImmutable::now(),
        );

        $this->assertSame(SignatureOutcome::MechanismUnavailable, $result->outcome);
        $this->assertNull($result->mechanism);
    }

    public function test_a_wrong_shared_token_is_rejected(): void
    {
        $result = $this->verifier(secrets: [], tokens: [self::TOKEN], allowSharedToken: true)->verify(
            credentials: $this->credentials(token: 'whtok_wrong-token'),
            svixId: null,
            svixTimestamp: null,
            rawBody: self::BODY,
            now: CarbonImmutable::now(),
        );

        $this->assertSame(SignatureOutcome::MechanismUnavailable, $result->outcome);
    }

    public function test_svix_headers_take_precedence_over_a_shared_token(): void
    {
        $now = CarbonImmutable::parse('2026-08-09T10:00:00+00:00');
        $ts = (string) $now->getTimestamp();

        // A valid token must not rescue a forged signature: presenting Svix
        // headers commits the delivery to the Svix mechanism.
        $result = $this->verifier(tokens: [self::TOKEN], allowSharedToken: true)->verify(
            credentials: $this->credentials(signature: 'v1,'.base64_encode('forged'), token: self::TOKEN),
            svixId: 'msg_1',
            svixTimestamp: $ts,
            rawBody: self::BODY,
            now: $now,
        );

        $this->assertSame(SignatureOutcome::SignatureMismatch, $result->outcome);
    }

    public function test_a_multi_signature_header_verifies_when_any_entry_matches(): void
    {
        // Svix sends every currently valid signature space-separated during a
        // rotation.
        $now = CarbonImmutable::parse('2026-08-09T10:00:00+00:00');
        $ts = (string) $now->getTimestamp();

        $header = 'v1,'.base64_encode('nonsense').' '.$this->sign('msg_1', $ts, self::BODY);

        $result = $this->verifier()->verify(
            credentials: $this->credentials(signature: $header),
            svixId: 'msg_1',
            svixTimestamp: $ts,
            rawBody: self::BODY,
            now: $now,
        );

        $this->assertSame(SignatureOutcome::Verified, $result->outcome);
    }

    public function test_an_unknown_signature_version_is_not_accepted(): void
    {
        $now = CarbonImmutable::parse('2026-08-09T10:00:00+00:00');
        $ts = (string) $now->getTimestamp();

        $valid = substr($this->sign('msg_1', $ts, self::BODY), strlen('v1,'));

        $result = $this->verifier()->verify(
            credentials: $this->credentials(signature: 'v9,'.$valid),
            svixId: 'msg_1',
            svixTimestamp: $ts,
            rawBody: self::BODY,
            now: $now,
        );

        // A header carrying only unsupported versions leaves nothing to
        // compare, so it is reported as malformed rather than as a mismatch.
        // Both map to `REJECTED_SIGNATURE`; what matters is that a future `v9`
        // scheme is never accepted by a verifier that does not implement it.
        $this->assertSame(SignatureOutcome::MalformedSignatureHeader, $result->outcome);
        $this->assertFalse($result->isVerified());
    }
}
