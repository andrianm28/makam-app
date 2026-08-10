<?php

declare(strict_types=1);

namespace App\Platform\Payment\Jobs;

use App\Platform\Outbox\OutboxQueueName;
use App\Platform\Payment\ProcessWebhookEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * AC5's "and then process it asynchronously": dispatched by `ReceiveWebhook`
 * the moment a delivery reaches `VALIDATED`, so the HTTP response can be
 * returned without waiting for any domain work.
 *
 * ---------------------------------------------------------------------------
 * What it does, and the larger half it deliberately still does not do
 * ---------------------------------------------------------------------------
 * UPDATED by Task 4 (10 Aug 2026). This was a deliberate shell while Task 3
 * owned "persist → validate → ack ≤ 2 s → dispatch". It now delegates to
 * `ProcessWebhookEvent`, which performs the two claims Wave 1b ruling 1b-L3-04
 * re-scoped Task 4 to: the `VALIDATED -> PROCESSING` row claim under
 * `SELECT ... FOR UPDATE`, and the `(provider, provider_transaction_id)`
 * apply-time settlement claim.
 *
 * The plan's original Task 4 also named `ApplyWebhookEffect` — the `PAID`
 * transition, the domain `DIBAYAR` state, the same-transaction `Journal::post()`,
 * and the `payment.received.v1` outbox event. None of those is built: every one
 * of them acts on a `payment_sessions` row, and under ruling 1b-L3-01 no such
 * row can exist. That apply half is recorded as NOT TESTED by name in the Task 4
 * report rather than stubbed out here as dead code.
 *
 * `handle()` therefore still must NOT mark the row `PROCESSED`, because nothing
 * has been processed — a status that claimed otherwise would be a false record
 * in the table design.md calls "the replay source of truth", and
 * `provider_events` is append-only precisely so that a false claim could not be
 * quietly corrected later. A claimed row rests at `PROCESSING`.
 *
 * ---------------------------------------------------------------------------
 * Two properties that are real now and must survive Task 4
 * ---------------------------------------------------------------------------
 * 1. **It carries an id, never a model and never a payload.** AC14: no
 *    credential and no provider payload may enter a queue payload. A row id is
 *    the whole constructor. `ProcessWebhookEvent` re-fetches fresh state under a
 *    lock, which is what makes the at-least-once redelivery `AGENTS.md` §Queue
 *    and event reliability guarantees safe to handle idempotently.
 * 2. **It runs on `critical`.** `queue-and-outbox.md` §2 via
 *    `OutboxQueueName::Critical`, set in the constructor so the queue travels
 *    with the job rather than depending on every dispatch site remembering
 *    `->onQueue()`. `AGENTS.md`: "Imports/reports/media must not starve
 *    critical or urgent queues."
 *
 * NOT TESTED end to end: no dispatch of this job can be triggered through the
 * HTTP receiver today, because no `provider_events` row can reach `VALIDATED`
 * while `payment_sessions` is uncreatable (Wave 1b ruling 1b-L3-01 — see
 * `WebhookValidator`). What IS tested is that the job is queueable, that it
 * targets `critical`, that it carries only an id, and — by invoking `handle()`
 * against a directly seeded `VALIDATED` row — that it really performs the claim
 * (`Tests\Feature\Payment\ProcessWebhookEventTest`).
 */
final class ProcessProviderEventJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $providerEventId,
    ) {
        $this->onQueue(OutboxQueueName::Critical->value);
    }

    /**
     * The action is resolved by the container rather than constructed here, so
     * the job payload stays a single id (property 1 above) and the claim stays
     * exercisable without a queue.
     */
    public function handle(ProcessWebhookEvent $process): void
    {
        // The outcome is deliberately not acted on: every case is a normal,
        // terminal result for this job. `NotClaimable` is at-least-once
        // redelivery working; `NotFound` is a stale dispatch against an
        // append-only row that is never deleted; `SettlementConflict` has
        // already recorded itself. None of them is retryable, so none of them
        // throws — see `ProcessWebhookEventOutcome`.
        $process($this->providerEventId);
    }
}
