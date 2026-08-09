<?php

declare(strict_types=1);

namespace App\Platform\Payment\Jobs;

use App\Platform\Outbox\OutboxQueueName;
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
 * Deliberately a shell — the apply logic is Task 4, not this task
 * ---------------------------------------------------------------------------
 * The implementation plan splits the pipeline: Task 3 owns "persist → validate
 * → ack ≤ 2 s → dispatch", and Task 4 owns `ProcessWebhookEvent` /
 * `ApplyWebhookEffect` — the claim-under-lock, the `PAID` transition, the
 * same-transaction `Journal::post()`, and the `payment.received.v1` outbox
 * event. The Task 3 brief is explicit: "`ProcessProviderEventJob` is dispatched
 * here but stays a thin shell; its apply logic is Task 4."
 *
 * `handle()` therefore does nothing at all, and says so rather than pretending:
 * a row that reaches `VALIDATED` stays there until Task 4 lands. It must NOT
 * mark the row `PROCESSED`, because nothing has been processed — a status that
 * claimed otherwise would be a false record in the table design.md calls "the
 * replay source of truth", and `provider_events` is append-only precisely so
 * that a false claim could not be quietly corrected later.
 *
 * ---------------------------------------------------------------------------
 * Two properties that are real now and must survive Task 4
 * ---------------------------------------------------------------------------
 * 1. **It carries an id, never a model and never a payload.** AC14: no
 *    credential and no provider payload may enter a queue payload. A row id is
 *    the whole constructor. Task 4 re-fetches fresh state in `handle()`, which
 *    is also what makes the at-least-once redelivery `AGENTS.md` §Queue and
 *    event reliability guarantees safe to handle idempotently.
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
 * targets `critical`, and that it carries only an id.
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

    public function handle(): void
    {
        // Intentionally empty — see the class doc block. Task 4 replaces this
        // body with the claim + apply + journal + outbox sequence.
    }
}
