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
 * What it does, and the half Task 4 deliberately did not build
 * ---------------------------------------------------------------------------
 * UPDATED by Task 4 (10 Aug 2026). This was a deliberate shell while Task 3
 * owned "persist → validate → ack ≤ 2 s → dispatch". It now delegates to
 * `ProcessWebhookEvent`, which performs the two claims Wave 1b ruling 1b-L3-04
 * re-scoped Task 4 to: the `VALIDATED -> PROCESSING` row claim under
 * `SELECT ... FOR UPDATE`, and the `(provider, provider_transaction_id)`
 * apply-time settlement claim.
 *
 * Task 5 (14 Aug 2026) wired the apply half the ruling deferred: a claimed
 * settling event is dispatched to `Actions\ApplyPaymentSettlement` inside the
 * claim transaction — the `PAID`/`DIBAYAR` state, the same-transaction
 * `payment.received.v1` outbox emission (booking), the marketplace
 * `payment_state` + vendor payable release, and the audit rows. The claim
 * ends at `PROCESSED`.
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
 * ---------------------------------------------------------------------------
 * Failure semantics since Task 5
 * ---------------------------------------------------------------------------
 * `handle()` does not act on the returned OUTCOME (every case is a normal,
 * terminal result for this job), but a SETTLEMENT failure is not an outcome —
 * it throws, propagating out of `ProcessWebhookEvent`'s transaction and out
 * of `handle()`. The claim rolls back with it (the row stays VALIDATED, no
 * effect is half-applied), the job fails, and the queue retries. A
 * permanently unresolvable event keeps failing until a human intervenes —
 * recorded as the intended fail-closed behaviour in `ProcessWebhookEvent`.
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
        // throws — see `ProcessWebhookEventOutcome`. A thrown SETTLEMENT
        // failure is a different animal: it propagates (the claim rolled
        // back, the row stays VALIDATED) so the queue retry can re-claim it.
        $process($this->providerEventId);
    }
}
