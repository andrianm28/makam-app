<?php

declare(strict_types=1);

namespace App\Platform\Payment\Jobs;

use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditOutcome;
use App\Platform\Audit\AuditSource;
use App\Platform\Audit\AuditSubject;
use App\Platform\Outbox\OutboxQueueName;
use App\Platform\Payment\Exceptions\SettlementTargetUnresolvableException;
use App\Platform\Payment\Models\ProviderEvent;
use App\Platform\Payment\PaymentAuditActions;
use App\Platform\Payment\ProcessWebhookEvent;
use App\Platform\Payment\ProviderEventStatus;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Throwable;

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
 * effect is half-applied) and the job is retried — BOUNDED, so a transient
 * failure (a database blip, a brief provider anomaly) is absorbed without
 * human attention while a permanent one still fails loudly:
 *
 *   - `$tries = 3` caps the attempt count (the worker's `--tries=1` default
 *     would otherwise strand the row on the first transient failure).
 *   - `retryUntil()` caps the whole retry window at fifteen minutes — in this
 *     Laravel version the worker's fail decision reads `retryUntil` when it
 *     is present (`Worker::markJobAsFailedIfWillExceedMaxAttempts`), so the
 *     window is the hard bound; `$tries` is declared so the attempt cap holds
 *     under either reading.
 *   - `backoff()` spaces the attempts so a failing event does not spin on the
 *     critical queue between retries.
 *
 * After the last retry the job moves to `failed_jobs`. Before Batch M1b
 * (PAY-02, 7 Sep 2026) that was the end of the trail: the row was left
 * `VALIDATED` forever, visible only to whoever happened to be watching
 * `failed_jobs` directly — no `MANUAL_REVIEW` status, no audit row, no
 * admin-panel queue. `failed()` below closes that gap: it moves the row to
 * `MANUAL_REVIEW` with a closed-list `rejection_detail` and writes one
 * `PaymentAuditActions::SETTLEMENT_PERMANENTLY_FAILED` audit row, in its own
 * transaction (there is no ambient one left by the time `failed()` runs —
 * the last attempt's own transaction already rolled back with the exception
 * that triggered this). The row is now re-claimable once a human resolves
 * the underlying cause AND re-dispatches it (a `MANUAL_REVIEW` row is not
 * automatically retried by anything), surfaced in
 * `App\Filament\Admin\Widgets\PaymentSettlementManualReviewQueueWidget` —
 * never a silent drop, and never a `PROCESSED` row for work that did not
 * commit.
 */
final class ProcessProviderEventJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Attempt cap — see the class doc block for the precedence between this
     * and `retryUntil()`.
     */
    public int $tries = 3;

    /**
     * The retry window, re-measured from EACH attempt: `now() + 15 minutes`
     * is recomputed every time the worker asks, so a still-failing job is
     * failed permanently fifteen minutes after its CURRENT attempt, not
     * fifteen minutes after its first. That is a sliding window — each retry
     * pushes the deadline forward — and the class doc block's "caps the whole
     * retry window at fifteen minutes" is the attempt-level reading of the
     * same code, not a promise about the first attempt. This is deliberate:
     * the cap is what keeps a failing event from spinning on the critical
     * queue indefinitely, and `$tries = 3` is the hard attempt cap that
     * bounds the window in practice.
     */
    public function retryUntil(): CarbonImmutable
    {
        return CarbonImmutable::now()->addMinutes(15);
    }

    /**
     * Spacing between retries, in seconds, per attempt number (0-based).
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 60, 180];
    }

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

    /**
     * Batch M1b, PAY-02. Called by the queue worker once this job has
     * exhausted its retries (`$tries`/`retryUntil()`) and is about to land in
     * `failed_jobs` — see the class doc block's "Failure semantics" section.
     *
     * Re-fetches the row under `lockForUpdate()` rather than trusting any
     * cached state: a concurrent successful claim (a redelivered webhook that
     * finally landed after this job's last attempt failed for an unrelated
     * reason) or an earlier `failed()` invocation may already have moved it
     * past `VALIDATED`, and this must never regress a row a later event
     * already resolved — the same "re-check the status inside the lock, act
     * only if it is still what you expect" discipline
     * `ProcessWebhookEvent::__invoke()` uses for its own claim.
     *
     * `AuditSource::Job`, not `Api`: this runs on the queue worker process
     * after the job has already failed, not on the HTTP path any of
     * `ProcessWebhookEvent`'s own audit writes run on.
     */
    public function failed(Throwable $e): void
    {
        DB::transaction(function () use ($e): void {
            $event = ProviderEvent::query()->whereKey($this->providerEventId)->lockForUpdate()->first();

            if ($event === null) {
                return;
            }

            if (ProviderEventStatus::tryFrom((string) $event->status) !== ProviderEventStatus::Validated) {
                return;
            }

            $event->markStatus(ProviderEventStatus::ManualReview, self::rejectionDetailFor($e));

            Audit::record(
                action: PaymentAuditActions::SETTLEMENT_PERMANENTLY_FAILED,
                subject: new AuditSubject('provider_event', $event->getKey()),
                outcome: AuditOutcome::Denied,
                // No authenticated actor by nature — this is a queue worker
                // recovering from an exhausted retry budget, not a request
                // any human or credentialed caller made.
                actorRef: null,
                actorRole: 'system',
                source: AuditSource::Job,
                correlationId: $event->correlation_id,
                metadata: ['note' => self::auditNoteFor($e)],
            );
        });
    }

    /**
     * Closed-list mapping from the exhausted exception to a short,
     * operator-facing detail — never the exception's own message, which
     * could carry any restricted value from the original failure (AC14).
     * Bounded to the column width by `ProviderEvent::markStatus()`, but kept
     * short here regardless — the closed list, not the truncation, is what
     * keeps this safe.
     */
    private static function rejectionDetailFor(Throwable $e): string
    {
        return match (true) {
            $e instanceof SettlementTargetUnresolvableException => 'settlement target unresolvable; retries exhausted',
            default => 'settlement failed; retries exhausted',
        };
    }

    /**
     * Same closed-list discipline as {@see rejectionDetailFor()}, phrased for
     * the audit `note` (`App\Platform\Audit\MetadataAllowlist::ALLOWED_KEYS`).
     */
    private static function auditNoteFor(Throwable $e): string
    {
        return match (true) {
            $e instanceof SettlementTargetUnresolvableException => 'settlement retries exhausted: target unresolvable',
            default => 'settlement retries exhausted: permanent failure',
        };
    }
}
