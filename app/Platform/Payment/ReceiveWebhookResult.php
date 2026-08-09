<?php

declare(strict_types=1);

namespace App\Platform\Payment;

/**
 * What `ReceiveWebhook` concluded, and the HTTP status the controller returns
 * for it.
 *
 * ---------------------------------------------------------------------------
 * Almost everything acks 200, on purpose
 * ---------------------------------------------------------------------------
 * The plan's Task 3: on a validation failure, "set `status = REJECTED_*`,
 * `Audit::record(PAYMENT_WEBHOOK_REJECTED, outcome denied)`, still ack 200 (the
 * provider doesn't need to know we rejected; the record is the truth, AC6
 * 'record and reject')."
 *
 * Two reasons, both worth stating because "return 200 on a rejection" reads
 * wrong at a glance:
 *   - A non-2xx makes ADR-0033's provider retry the delivery. Retrying a
 *     forged or mismatched webhook cannot make it valid, so the retry storm
 *     buys nothing and costs a durable row per attempt.
 *   - A status that varied with the rejection reason would be an oracle: an
 *     unauthenticated caller could probe which merchant references exist, which
 *     transactions have sessions, and which amounts match, one request at a
 *     time. The response is therefore identical in shape and content for every
 *     outcome except the transport-level one below.
 *
 * The single exception is `PayloadTooLarge`, which is not a webhook validation
 * outcome at all — the body was never read as a webhook. A 413 tells the
 * provider the request itself was unacceptable and is honest about the fact
 * that nothing was stored.
 */
final readonly class ReceiveWebhookResult
{
    private function __construct(
        public ProviderEventStatus $status,
        /**
         * `payment-webhook.md` §Idempotency: a duplicate "returns a success
         * acknowledgment and the original processing reference". This is that
         * reference — the `provider_events` row id, which is a UUID and
         * discloses nothing.
         */
        public ?string $reference,
        public int $httpStatus,
        public bool $stored,
    ) {}

    public static function acknowledged(ProviderEventStatus $status, ?string $reference): self
    {
        return new self($status, $reference, 200, true);
    }

    public static function payloadTooLarge(): self
    {
        return new self(ProviderEventStatus::RejectedPayload, null, 413, false);
    }
}
