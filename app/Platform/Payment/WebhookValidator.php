<?php

declare(strict_types=1);

namespace App\Platform\Payment;

use App\Platform\Payment\Http\InboundWebhook;
use App\Platform\Payment\Http\WebhookCredentials;
use App\Platform\Payment\Models\PaymentSession;
use App\Platform\Payment\Models\ProviderEvent;
use App\Platform\Payment\Providers\SignatureMechanism;
use App\Platform\Payment\Providers\SignatureOutcome;
use App\Platform\Payment\Providers\SignatureVerification;
use App\Platform\Payment\Providers\SumoPodWebhookSignature;
use Carbon\CarbonImmutable;

/**
 * AC6: "THE SYSTEM SHALL validate every webhook's signature, merchant scope,
 * amount, currency, and replay window. WHEN validation fails THE SYSTEM SHALL
 * record and reject the webhook, and SHALL NOT silently ignore it."
 *
 * This class only DECIDES. It writes nothing: `ReceiveWebhook` records the
 * decision on the already-persisted `provider_events` row and raises the audit
 * event. Keeping the decision pure is what lets every rejection path be tested
 * without an HTTP kernel, and makes it structurally impossible for a
 * validation step to have a side effect on the way to saying "no".
 *
 * ---------------------------------------------------------------------------
 * Order of checks, and why it is this order
 * ---------------------------------------------------------------------------
 * `payment-webhook.md` §Processing pipeline fixes most of it:
 * "signature and timestamp validated -> merchant/badan_usaha validated ->
 * idempotency key checked -> invoice and amount matched".
 *
 *  1. **Envelope** — a body that cannot be read cannot be checked against
 *     anything. `REJECTED_PAYLOAD`.
 *  2. **Signature**, then the **replay window** (`SumoPodWebhookSignature`
 *     enforces that sub-order deliberately — see its doc block).
 *     `REJECTED_SIGNATURE` / `REJECTED_REPLAY`.
 *  3. **Declared currency**, when the body declares one. Session-independent,
 *     so it is checked before anything that needs a session; otherwise a wrong
 *     currency would be masked by `REJECTED_SESSION` and AC6's currency
 *     requirement would have no reachable evidence. `REJECTED_CURRENCY`.
 *  4. **Non-positive amount.** Also session-independent, and the Task 2 review
 *     recorded a binding forward constraint that the first real money path
 *     must reject non-positive amounts. `REJECTED_AMOUNT`.
 *  5. **Merchant scope** — the `{merchant}` route segment must be one this
 *     environment answers for (`config('payment.webhook.merchants')`).
 *     `REJECTED_MERCHANT`.
 *  6. **Session binding** — the session this provider transaction belongs to.
 *     `REJECTED_SESSION`.
 *  7. **AC13 reconciliation** — the session's `merchant_ref` must equal the
 *     endpoint the event arrived on, and its `badan_usaha_ref` must be intact.
 *     `REJECTED_MERCHANT`.
 *  8. **Session currency**, then **amount** in integer minor units.
 *     `REJECTED_CURRENCY` / `REJECTED_AMOUNT`.
 *
 * ---------------------------------------------------------------------------
 * Steps 6-8 cannot pass today, and that is the correct behaviour
 * ---------------------------------------------------------------------------
 * Wave 1b ruling 1b-L3-01 made `GuardPaymentSession` deny-only: five of its six
 * conditions have no authoritative upstream record in this repository, so there
 * is no reachable PASS, no `CreatePaymentSession`, and therefore **no
 * `payment_sessions` row can exist** — `Models\PaymentSession` refuses to
 * insert, and `PaymentGuardFailClosedTest` pins that.
 *
 * So every well-formed, correctly signed webhook arriving today stops at step 6
 * with `REJECTED_SESSION`. That is fail-closed behaviour working exactly as
 * ruling 1b-L3-01 intends — a webhook that cannot be tied to a session this
 * system authorized must never be treated as evidence of payment (`AGENTS.md`
 * §Domain and financial invariants). The ruling's instruction for downstream
 * tasks meeting this wall is to escalate rather than widen scope, so steps 6-8
 * are written to be REAL against a session the moment one can exist, and are
 * exercised here only through the rejection they produce. No session fixture is
 * fabricated and no test-only bypass exists; the pass path is honestly
 * NOT TESTED, and is recorded as such in the Task 3 report.
 */
final readonly class WebhookValidator
{
    public function __construct(private SumoPodWebhookSignature $signature) {}

    public function validate(InboundWebhook $inbound, WebhookEnvelope $envelope): WebhookValidation
    {
        if (! $envelope->isWellFormed()) {
            $detail = 'envelope: '.$envelope->problem?->value;

            if ($envelope->problemField !== null) {
                $detail .= ' ('.$envelope->problemField.')';
            }

            return WebhookValidation::rejected(
                ProviderEventStatus::RejectedPayload,
                $detail,
            );
        }

        $signature = $this->signature->verify(
            credentials: $inbound->credentials,
            svixId: $inbound->svixId,
            svixTimestamp: $inbound->svixTimestamp,
            rawBody: $inbound->rawBody,
            now: $inbound->receivedAt(),
        );

        if (! $signature->isVerified()) {
            return $signature->outcome === SignatureOutcome::TimestampOutsideWindow
                ? WebhookValidation::rejected(
                    ProviderEventStatus::RejectedReplay,
                    'timestamp outside replay window',
                )
                : WebhookValidation::rejected(
                    ProviderEventStatus::RejectedSignature,
                    'signature: '.$this->signatureDetail($signature->outcome),
                );
        }

        $mechanism = $signature->mechanism ?? SignatureMechanism::Svix;
        $expectedCurrency = (string) config('money.currency');

        if ($envelope->declaredCurrency !== null
            && strcasecmp($envelope->declaredCurrency, $expectedCurrency) !== 0) {
            return WebhookValidation::rejected(
                ProviderEventStatus::RejectedCurrency,
                'declared currency is not the configured currency',
                $mechanism,
            );
        }

        // Wave 0 ruling 0c keeps this an integer comparison end to end; a zero
        // or negative settlement amount is not a payment under any reading.
        if ($envelope->amountMinor === null || $envelope->amountMinor <= 0) {
            return WebhookValidation::rejected(
                ProviderEventStatus::RejectedAmount,
                'amount is not a positive integer minor-unit value',
                $mechanism,
            );
        }

        if (! $this->isKnownMerchant($inbound->merchantRef)) {
            return WebhookValidation::rejected(
                ProviderEventStatus::RejectedMerchant,
                'merchant endpoint is not configured for this environment',
                $mechanism,
            );
        }

        $session = $this->findSession($inbound->provider, $envelope->providerTransactionId);

        if ($session === null) {
            return WebhookValidation::rejected(
                ProviderEventStatus::RejectedSession,
                'no payment session is bound to this provider transaction',
                $mechanism,
            );
        }

        // ---- Everything below is unreachable today; see the class doc block.

        // AC13: "bind merchant and `badan_usaha` explicitly on every session
        // and … reconcile the binding on every webhook." ADR-0033's envelope
        // carries neither field, so the merchant-scoped route segment is the
        // only merchant assertion the delivery makes, and it must agree with
        // the binding recorded when the session was created.
        if (! hash_equals($session->merchant_ref, $inbound->merchantRef)) {
            return WebhookValidation::rejected(
                ProviderEventStatus::RejectedMerchant,
                'session merchant binding does not match the endpoint',
                $mechanism,
            );
        }

        if (trim((string) $session->badan_usaha_ref) === '') {
            return WebhookValidation::rejected(
                ProviderEventStatus::RejectedMerchant,
                'session badan_usaha binding is absent',
                $mechanism,
            );
        }

        if (strcasecmp((string) $session->currency, $expectedCurrency) !== 0) {
            return WebhookValidation::rejected(
                ProviderEventStatus::RejectedCurrency,
                'session currency is not the configured currency',
                $mechanism,
            );
        }

        // Integer minor units on both sides — `payment_sessions.amount_minor`
        // is cast `integer` and the envelope amount came through
        // `Money::fromDecimal()`. No float, no tolerance, no rounding.
        if ($session->amount_minor !== $envelope->amountMinor) {
            return WebhookValidation::rejected(
                ProviderEventStatus::RejectedAmount,
                'amount does not match the session amount snapshot',
                $mechanism,
            );
        }

        return WebhookValidation::passed($mechanism);
    }

    public function verifyStoredEvidence(ProviderEvent $event, CarbonImmutable $now): SignatureVerification
    {
        if ($event->event_id_source !== 'svix-id'
            || $event->signature_timestamp === null
            || $event->signature_header === null) {
            return SignatureVerification::failed(SignatureOutcome::MalformedSignatureHeader);
        }

        return $this->signature->verify(
            credentials: new WebhookCredentials(svixSignature: $event->signature_header),
            svixId: $event->provider_event_id,
            svixTimestamp: $event->signature_timestamp,
            rawBody: $event->raw_payload,
            now: $now,
        );
    }

    private function findSession(string $provider, string $providerTransactionId): ?PaymentSession
    {
        return PaymentSession::query()
            ->where('provider', $provider)
            ->where('provider_payment_id', $providerTransactionId)
            ->first();
    }

    private function isKnownMerchant(string $merchantRef): bool
    {
        /** @var list<string> $merchants */
        $merchants = (array) config('payment.webhook.merchants', []);

        foreach ($merchants as $known) {
            if (hash_equals((string) $known, $merchantRef)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A closed-list label, never the presented signature or any part of it.
     */
    private function signatureDetail(SignatureOutcome $outcome): string
    {
        return match ($outcome) {
            SignatureOutcome::NotConfigured => 'no verification credential is configured',
            SignatureOutcome::MechanismUnavailable => 'no enabled verification mechanism was presented',
            SignatureOutcome::MalformedSignatureHeader => 'signature headers are incomplete or malformed',
            SignatureOutcome::TimestampMalformed => 'timestamp header is not an integer',
            SignatureOutcome::SignatureMismatch => 'presented credential did not match',
            SignatureOutcome::Verified,
            SignatureOutcome::TimestampOutsideWindow => 'unexpected outcome',
        };
    }
}
