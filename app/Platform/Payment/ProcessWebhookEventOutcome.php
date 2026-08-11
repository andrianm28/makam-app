<?php

declare(strict_types=1);

namespace App\Platform\Payment;

/**
 * What `ProcessWebhookEvent` did with one `provider_events` row.
 *
 * A returned outcome rather than a boolean or a thrown exception, for two
 * reasons. First, "the row was already claimed" is a NORMAL result — queue
 * delivery is at-least-once (`AGENTS.md` §Queue and event reliability), so a
 * second worker seeing an already-claimed row is the idempotency mechanism
 * working, not an error to retry or alert on. Second, every outcome below is
 * distinguishable in a test, which is what makes the exactly-once property
 * assertable rather than merely asserted in a doc block.
 */
enum ProcessWebhookEventOutcome
{
    /**
     * The row moved `VALIDATED -> PROCESSING` and this caller owns it.
     *
     * It does NOT mean anything was applied — see `ProcessWebhookEvent`'s doc
     * block for why the apply half does not exist yet.
     */
    case Claimed;

    /**
     * The row was not at `VALIDATED`, so there was nothing to claim: another
     * worker already claimed it, it was already processed, or it never passed
     * validation at all. No write happened.
     */
    case NotClaimable;

    /**
     * No `provider_events` row carries that id. A queue payload naming a row
     * that does not exist is not retryable — the row is append-only and is
     * never deleted, so it can only mean a stale or malformed dispatch.
     */
    case NotFound;

    /**
     * The row is a settling event for a provider transaction another settling
     * event has already claimed. `docs/contracts/payment-webhook.md`
     * §Idempotency: one provider transaction must never settle multiple
     * invoices. The row is moved to `MANUAL_REVIEW` and audited — never
     * applied, and never silently dropped.
     */
    case SettlementConflict;
}
