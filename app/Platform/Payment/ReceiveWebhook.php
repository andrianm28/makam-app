<?php

declare(strict_types=1);

namespace App\Platform\Payment;

use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditOutcome;
use App\Platform\Audit\AuditSource;
use App\Platform\Audit\AuditSubject;
use App\Platform\Payment\Http\InboundWebhook;
use App\Platform\Payment\Jobs\ProcessProviderEventJob;
use App\Platform\Payment\Models\ProviderEvent;
use Illuminate\Database\QueryException;

/**
 * AC5: "WHEN a webhook is received THE SYSTEM SHALL persist it, acknowledge it
 * within 2 seconds, and then process it asynchronously."
 *
 * ---------------------------------------------------------------------------
 * Persist BEFORE validate — the ordering is the requirement, not an accident
 * ---------------------------------------------------------------------------
 * The durable row is written while nothing is yet known to be true about the
 * delivery. That is the point: AC6 requires that a failed validation be
 * *recorded* and never silently ignored, and there is nowhere to record it if
 * the record is only created for deliveries that pass. A forged webhook leaves
 * evidence in this system; a dropped one would not.
 *
 * The consequence is stated plainly rather than left implicit: this endpoint
 * writes one row per accepted request before it can authenticate anything. It
 * is therefore rate-limited at the route (`config('payment.webhook.throttle')`,
 * registered in `Providers\PaymentServiceProvider`) and size-capped here.
 * Signature verification does NOT substitute for the throttle, because the row
 * exists before the signature is examined. This is the binding forward
 * constraint the Task 2 review recorded: "The task that wires the first HTTP
 * caller MUST require an authenticated actor and/or throttle before the guard
 * is invoked" — a provider webhook cannot present an authenticated actor (the
 * provider holds no credential of ours), so throttling is the whole answer.
 *
 * ---------------------------------------------------------------------------
 * Decoding the body is not validating it
 * ---------------------------------------------------------------------------
 * `WebhookEnvelope::parse()` runs before the insert so the row can carry the
 * transaction id, invoice reference, event type and amount that make it
 * queryable and that the secondary unique guard needs. Parsing asserts nothing
 * about authenticity: a body that parses fine is still rejected a few lines
 * later if its signature does not verify. The bytes are stored verbatim
 * regardless, and are never re-decoded afterwards (the plan: "never
 * JSON-decoded-again after persist").
 *
 * ---------------------------------------------------------------------------
 * Nothing slow, remote, or asynchronous happens in the request path
 * ---------------------------------------------------------------------------
 * The whole of the work is: one parse, one INSERT, in-memory validation, one
 * UPDATE of the lifecycle column, one audit INSERT on a rejection, and one
 * queue push on success. No provider round-trip, no outbound HTTP, no job
 * executed inline, no external lookup. That is how the 2-second ack
 * (`payment-webhook.md` §5, which the plan adopts over ADR-0033's looser
 * 10-second ceiling) is met — by construction rather than by measurement.
 */
final readonly class ReceiveWebhook
{
    private const string EVENT_ID_SOURCE_SVIX = 'svix-id';

    private const string EVENT_ID_SOURCE_DIGEST = 'body-digest';

    public function __construct(private WebhookValidator $validator) {}

    public function __invoke(InboundWebhook $inbound): ReceiveWebhookResult
    {
        $maxBytes = (int) config('payment.webhook.max_body_bytes', 65536);

        if (strlen($inbound->rawBody) > $maxBytes) {
            // Nothing is stored: an oversized body is exactly the shape of
            // storage abuse this endpoint has to refuse, and storing a
            // truncated body would put something in the replay source of truth
            // that the provider never sent. The refusal is still recorded —
            // an audit row is bounded by the same throttle that bounds
            // everything else here.
            $this->auditRejection(
                subjectId: 'unstored',
                status: ProviderEventStatus::RejectedPayload,
                detail: 'body exceeds the configured maximum size',
                inbound: $inbound,
            );

            return ReceiveWebhookResult::payloadTooLarge();
        }

        $envelope = WebhookEnvelope::parse($inbound->rawBody);

        try {
            $event = $this->persist($inbound, $envelope);
        } catch (QueryException $exception) {
            return $this->resolveDuplicate($inbound, $envelope, $exception);
        }

        $validation = $this->validator->validate($inbound, $envelope);

        if ($validation->isValid()) {
            $event->signature_mechanism = $validation->mechanism?->value;
            $event->markStatus(ProviderEventStatus::Validated);

            // AC5's "and then process it asynchronously". A queue push, not
            // the work itself — see `ProcessProviderEventJob`, whose apply
            // logic belongs to Task 4.
            ProcessProviderEventJob::dispatch($event->getKey());

            return ReceiveWebhookResult::acknowledged(ProviderEventStatus::Validated, $event->getKey());
        }

        $event->signature_mechanism = $validation->mechanism?->value;
        $event->markStatus($validation->status, $validation->detail);

        $this->auditRejection(
            subjectId: $event->getKey(),
            status: $validation->status,
            detail: $validation->detail ?? 'unspecified',
            inbound: $inbound,
        );

        return ReceiveWebhookResult::acknowledged($validation->status, $event->getKey());
    }

    private function persist(InboundWebhook $inbound, WebhookEnvelope $envelope): ProviderEvent
    {
        [$eventId, $eventIdSource] = $this->resolveEventIdentity($inbound);

        return ProviderEvent::create([
            'provider' => $inbound->provider,
            'provider_event_id' => $eventId,
            'event_id_source' => $eventIdSource,
            'provider_transaction_id' => $envelope->providerTransactionId,
            'invoice_reference' => $envelope->invoiceReference,
            'event_type' => $envelope->eventType,
            'merchant_ref' => $inbound->merchantRef,
            'amount_minor' => $envelope->amountMinor,
            'declared_currency' => $envelope->declaredCurrency,
            'event_occurred_at' => $envelope->occurredAt,
            'raw_payload' => $inbound->rawBody,
            'payload_digest' => hash('sha256', $inbound->rawBody),
            'status' => ProviderEventStatus::Received->value,
            'received_at' => $inbound->receivedAt(),
            'correlation_id' => $inbound->correlationId,
        ]);
    }

    /**
     * AC7's identity: "process webhooks idempotently by provider event
     * identity".
     *
     * ADR-0033's webhook envelope has NO event-id field — the provider event
     * identity is the Svix message id in the `svix-id` header. When a delivery
     * carries no `svix-id` (the shared-token mechanism, which is disabled by
     * default), there is no provider-assigned identity to use, so a SHA-256
     * digest of the exact body stands in.
     *
     * That surrogate is stated as a limitation rather than presented as an
     * equivalent: it dedupes byte-identical redeliveries — which is what a
     * provider retry actually is — but two semantically identical deliveries
     * whose bytes differ would produce two rows. The failure mode is a
     * duplicate ROW, never a duplicate EFFECT: the settlement guard on
     * `(provider, provider_transaction_id, invoice_reference)` and Task 4's
     * apply-time claim both sit downstream of it. Recorded in
     * `provider_events.event_id_source` so the distinction survives in the data.
     *
     * @return array{string, string}
     */
    private function resolveEventIdentity(InboundWebhook $inbound): array
    {
        $svixId = $inbound->svixId;

        if ($svixId !== null && $svixId !== '' && preg_match('/\A[\x20-\x7E]{1,180}\z/D', $svixId) === 1) {
            return [$svixId, self::EVENT_ID_SOURCE_SVIX];
        }

        return ['sha256:'.hash('sha256', $inbound->rawBody), self::EVENT_ID_SOURCE_DIGEST];
    }

    /**
     * A unique-constraint collision on insert IS the idempotency mechanism —
     * `payment-webhook.md` §Idempotency: "Duplicate valid webhook returns a
     * success acknowledgment and the original processing reference. It must not
     * repeat journal, state, invoice, or notification effects."
     *
     * Which of the two guards fired is resolved by looking the original up
     * rather than by parsing the driver's error message, so the behaviour is
     * identical on PostgreSQL and SQLite. If neither lookup finds an original,
     * the exception was not an idempotency collision at all and is re-thrown —
     * a database error must never be laundered into a success ack.
     */
    private function resolveDuplicate(
        InboundWebhook $inbound,
        WebhookEnvelope $envelope,
        QueryException $exception,
    ): ReceiveWebhookResult {
        [$eventId] = $this->resolveEventIdentity($inbound);

        $original = ProviderEvent::query()
            ->where('provider', $inbound->provider)
            ->where('provider_event_id', $eventId)
            ->first();

        if ($original === null
            && $envelope->providerTransactionId !== null
            && $envelope->invoiceReference !== null) {
            $original = ProviderEvent::query()
                ->where('provider', $inbound->provider)
                ->where('provider_transaction_id', $envelope->providerTransactionId)
                ->where('invoice_reference', $envelope->invoiceReference)
                ->whereIn('event_type', ProviderEventType::settlingValues())
                ->first();
        }

        if ($original === null) {
            throw $exception;
        }

        // The original row is NOT re-statused: it is append-only evidence of
        // the first delivery, and a redelivery is not a change to what
        // happened then. The duplicate itself is recorded as an audit event,
        // which is the only durable record of it (the row it would have
        // written is precisely what the unique guard refused).
        Audit::record(
            action: PaymentAuditActions::WEBHOOK_DUPLICATE,
            subject: new AuditSubject('provider_event', $original->getKey()),
            outcome: AuditOutcome::Denied,
            actorRef: null,
            actorRole: 'provider',
            source: AuditSource::Api,
            correlationId: $inbound->correlationId,
            metadata: ['note' => sprintf(
                'duplicate delivery; provider=%s; original_status=%s',
                $inbound->provider,
                $original->status,
            )],
        );

        return ReceiveWebhookResult::acknowledged(ProviderEventStatus::Duplicate, $original->getKey());
    }

    /**
     * AC6's "record and reject". The `provider_events` row carries the machine
     * status; this is the human/audit trail, with `AuditOutcome::Denied` so a
     * rejection is never mistaken for a processed event.
     *
     * `note` is an EXISTING `MetadataAllowlist::ALLOWED_KEYS` key — this lane
     * adds none. Its value is built from closed-list statuses, the provider
     * slug, and `WebhookValidation::$detail`, which is itself composed only of
     * closed-list labels and field names: no amount, no identifier, no payload
     * value, no part of any credential (`AGENTS.md` §Observability, AC14).
     */
    private function auditRejection(
        string $subjectId,
        ProviderEventStatus $status,
        string $detail,
        InboundWebhook $inbound,
    ): void {
        Audit::record(
            action: PaymentAuditActions::WEBHOOK_REJECTED,
            subject: new AuditSubject('provider_event', $subjectId),
            outcome: AuditOutcome::Denied,
            // A webhook has no authenticated actor by nature — the provider
            // holds no credential of ours (which is exactly why the endpoint
            // is throttled). `actor_role` is still mandatory, per
            // `Audit::record()`'s contract.
            actorRef: null,
            actorRole: 'provider',
            source: AuditSource::Api,
            correlationId: $inbound->correlationId,
            metadata: ['note' => sprintf(
                'status=%s; provider=%s; detail=%s',
                $status->value,
                $inbound->provider,
                $detail,
            )],
        );
    }
}
