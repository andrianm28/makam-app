<?php

declare(strict_types=1);

namespace App\Platform\Payment;

use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditOutcome;
use App\Platform\Audit\AuditSource;
use App\Platform\Audit\AuditSubject;
use App\Platform\Payment\Models\ProviderEvent;
use Illuminate\Support\Facades\DB;

/**
 * The asynchronous half of AC5 ("… and then process it asynchronously"): the
 * claim `Jobs\ProcessProviderEventJob` runs behind the dispatch at
 * `ReceiveWebhook::finishValidation()`.
 *
 * ---------------------------------------------------------------------------
 * Scope — this class claims, it does not apply
 * ---------------------------------------------------------------------------
 * Wave 1b ruling 1b-L3-04 re-scoped Task 4. The plan's original Task 4 was
 * almost entirely downstream of a created `payment_sessions` row: the `PAID`
 * transition, the domain `DIBAYAR` state, the same-transaction `Journal::post()`,
 * and the `payment.received.v1` outbox emission. Under ruling 1b-L3-01 the
 * payment guard is deny-only, so no `payment_sessions` row can exist
 * (`Models\PaymentSession` refuses to insert, and `PaymentGuardFailClosedTest`
 * pins that). None of those effects has anything to act on, and the ruling is
 * explicit that fabricating a session — by factory, `unguarded()`, query
 * builder, raw SQL, or a test-only bypass — is prohibited rather than clever.
 *
 * So this class implements the two claims that ARE reachable, and stops. It
 * deliberately does NOT mark a row `PROCESSED`: nothing has been processed, and
 * `provider_events` is the append-only "replay source of truth" (design.md
 * §Data) where a false claim could not be quietly corrected later. A claimed row
 * rests at `PROCESSING`, which `ReceiveWebhook::resolveLockedDuplicate()`
 * already reads as "owned by a worker, do not create a second effect".
 *
 * The apply half is recorded as NOT TESTED, by name, in the Task 4 report. It is
 * not stubbed here, because a speculative empty apply step would be dead code
 * that a later reader could mistake for a real, exercised path.
 *
 * ---------------------------------------------------------------------------
 * Claim 1 — the row: `VALIDATED -> PROCESSING`, exactly once
 * ---------------------------------------------------------------------------
 * Queue delivery is at-least-once (`AGENTS.md` §Queue and event reliability), so
 * the same row id will be handed to a worker more than once. The claim is a
 * compare-and-set under a row lock, following the `OutboxPublisher::claim()`
 * precedent: read the row `FOR UPDATE`, re-check that it is still `VALIDATED`,
 * and only then move it. A second claimant finds `PROCESSING` and returns
 * `NotClaimable` without writing anything.
 *
 * Unlike `OutboxPublisher`, this class does NOT refuse to run on a non-Postgres
 * driver. `OutboxPublisher` must, because `SKIP LOCKED` is syntax SQLite cannot
 * parse at all; `lockForUpdate()` merely compiles to an empty string on SQLite
 * (`Illuminate\Database\Query\Grammars\SQLiteGrammar::compileLock`), so the
 * compare-and-set still runs and is still correct in sequence. That is stated as
 * a limitation, not glossed: on SQLite the claim degrades to an unserialized
 * compare-and-set, and the exactly-once property under CONCURRENCY holds only on
 * PostgreSQL. It is unproven locally and is recorded as NOT TESTED — see
 * `Tests\Feature\Payment\ProcessWebhookEventTest`.
 *
 * `FOR UPDATE`, not `FOR UPDATE SKIP LOCKED`: a second worker on the same row
 * must WAIT and then observe the committed status, not skip past it and leave
 * the caller unable to tell "claimed by someone else" from "not claimable".
 *
 * ---------------------------------------------------------------------------
 * Claim 2 — the provider transaction: `(provider, provider_transaction_id)`
 * ---------------------------------------------------------------------------
 * `docs/contracts/payment-webhook.md` §Idempotency: "A secondary guard should
 * prevent the same provider transaction from settling multiple invoices."
 *
 * Task 3 implemented the insert-time half — a PARTIAL unique index on
 * `(provider, provider_transaction_id, invoice_reference)` over settling event
 * types only — and recorded the remaining gap in the migration's own doc block:
 * that triple stops the same (transaction, invoice) pair settling twice, but not
 * one transaction settling two DIFFERENT invoices. Ruling 1b-L3-04 assigns the
 * gap here.
 *
 * WHY THE CLAIM IS HERE AND NOT AN INDEX. Uniqueness on
 * `(provider, provider_transaction_id)` alone cannot be an index, for two
 * independent reasons:
 *
 *   1. It would break AC5. `provider_events` is written BEFORE anything is known
 *      to be true about a delivery, precisely so AC6's "record and reject …
 *      SHALL NOT silently ignore it" has somewhere to write. A second settling
 *      event naming a different invoice for the same transaction is exactly the
 *      evidence of a provider bug or an attack that must be KEPT — an index
 *      would refuse the INSERT and destroy it.
 *   2. It would regress the property Task 3 preserved on purpose. Making the
 *      index total (dropping `invoice_reference` from the key) would make an
 *      out-of-order `expired`-after-`completed` delivery unpersistable, which
 *      the plan's own Task 4 requires to be receivable. Scoping it to settling
 *      types only would still refuse the second settling row.
 *
 * So the claim is applied at APPLY time, over rows that already exist: a
 * provider transaction may be claimed by at most ONE settling provider event.
 * The first settling event to claim it owns it; any later settling event for the
 * same transaction is refused, moved to `MANUAL_REVIEW`, and audited. Non-settling
 * events (`payment.failed`, `payment.expired`) are untouched by this claim, so
 * the out-of-order delivery Task 3 protected still persists AND still claims.
 *
 * The check does not compare invoice references. "Another settling event already
 * claimed this transaction" is the whole rule, which is strictly stricter than
 * "…with a different invoice" and cannot under-enforce.
 *
 * ---------------------------------------------------------------------------
 * How the transaction-scope lock avoids a race the row lock cannot see
 * ---------------------------------------------------------------------------
 * Two settling rows for one transaction are two DIFFERENT rows, so locking each
 * one by id would let two workers pass claim 2 simultaneously — each holding a
 * lock the other never contends for. The claim therefore locks the whole
 * settling set for the transaction, in `id` order (a deterministic order, so two
 * workers cannot deadlock against each other), and only then re-reads.
 *
 * The unlocked read that discovers which set to lock is safe because it reads
 * only `provider`, `provider_transaction_id`, and `event_type` — all of them
 * immutable columns under `Models\ProviderEvent::MUTABLE_COLUMNS`, so no
 * concurrent writer can change what set this row belongs to. Status is never
 * read from that first query; it is read only from inside the lock.
 */
final readonly class ProcessWebhookEvent
{
    /**
     * Statuses that mean a settling event has taken, or is holding, the claim
     * on its provider transaction.
     *
     * `MANUAL_REVIEW` is included deliberately: once a transaction has produced
     * a settlement conflict, a third invoice must not be able to quietly claim
     * it while a human is still deciding what happened. Fail-closed.
     *
     * @var list<ProviderEventStatus>
     */
    private const array CLAIMING_STATUSES = [
        ProviderEventStatus::Processing,
        ProviderEventStatus::Processed,
        ProviderEventStatus::ManualReview,
    ];

    public function __invoke(string $providerEventId): ProcessWebhookEventOutcome
    {
        return DB::transaction(function () use ($providerEventId): ProcessWebhookEventOutcome {
            $event = $this->lockClaimScope($providerEventId);

            if ($event === null) {
                return ProcessWebhookEventOutcome::NotFound;
            }

            if (ProviderEventStatus::tryFrom((string) $event->status) !== ProviderEventStatus::Validated) {
                return ProcessWebhookEventOutcome::NotClaimable;
            }

            $incumbent = $this->settlementIncumbent($event);

            if ($incumbent !== null) {
                $event->markStatus(
                    ProviderEventStatus::ManualReview,
                    'settlement conflict: provider transaction is already claimed by another settling event',
                );

                $this->auditSettlementConflict($event, $incumbent);

                return ProcessWebhookEventOutcome::SettlementConflict;
            }

            $event->markStatus(ProviderEventStatus::Processing);

            // Nothing is applied here — see the class doc block. The row rests
            // at PROCESSING until the session-dependent apply path exists.
            return ProcessWebhookEventOutcome::Claimed;
        });
    }

    /**
     * Locks everything this claim can be contended against, and returns the
     * target row as read from inside that lock.
     */
    private function lockClaimScope(string $providerEventId): ?ProviderEvent
    {
        $identity = ProviderEvent::query()->whereKey($providerEventId)->first();

        if ($identity === null) {
            return null;
        }

        $transactionId = $identity->provider_transaction_id;

        if ($transactionId === null || ! $this->isSettling($identity)) {
            return ProviderEvent::query()->whereKey($providerEventId)->lockForUpdate()->first();
        }

        $scope = ProviderEvent::query()
            ->where('provider', $identity->provider)
            ->where('provider_transaction_id', $transactionId)
            ->whereIn('event_type', ProviderEventType::settlingValues())
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        return $scope->first(
            static fn (ProviderEvent $row): bool => $row->getKey() === $providerEventId
        );
    }

    /**
     * The settling event that already holds this transaction's claim, if any.
     * Read from inside the lock taken by `lockClaimScope()`.
     */
    private function settlementIncumbent(ProviderEvent $event): ?ProviderEvent
    {
        $transactionId = $event->provider_transaction_id;

        if ($transactionId === null || ! $this->isSettling($event)) {
            return null;
        }

        return ProviderEvent::query()
            ->where('provider', $event->provider)
            ->where('provider_transaction_id', $transactionId)
            ->whereIn('event_type', ProviderEventType::settlingValues())
            ->whereKeyNot($event->getKey())
            ->whereIn(
                'status',
                array_map(
                    static fn (ProviderEventStatus $status): string => $status->value,
                    self::CLAIMING_STATUSES
                )
            )
            ->orderBy('id')
            ->first();
    }

    private function isSettling(ProviderEvent $event): bool
    {
        $type = ProviderEventType::tryFrom((string) $event->event_type);

        return $type !== null && $type->isSettling();
    }

    /**
     * A settlement conflict is a financial-integrity event: one provider
     * transaction attempting to settle a second invoice. The `MANUAL_REVIEW`
     * status is the machine record; this is the trail an operator reads, in the
     * same shape `ReceiveWebhook::auditRejection()` established.
     *
     * `note` is an EXISTING `App\Platform\Audit\MetadataAllowlist::ALLOWED_KEYS`
     * key — this lane adds none. Its value carries the provider slug and the
     * INTERNAL `provider_events` id of the incumbent row (the same class of
     * identifier as the audit subject itself), so an operator can find both
     * rows. It carries no invoice reference, no provider transaction id, no
     * amount, and no part of any payload — those are provider payload values
     * and `AGENTS.md` §Observability keeps them out of audit rows (AC14).
     */
    private function auditSettlementConflict(ProviderEvent $event, ProviderEvent $incumbent): void
    {
        Audit::record(
            action: PaymentAuditActions::WEBHOOK_SETTLEMENT_CONFLICT,
            subject: new AuditSubject('provider_event', $event->getKey()),
            outcome: AuditOutcome::Denied,
            // No authenticated actor by nature — a provider holds no credential
            // of ours. Same reasoning as `ReceiveWebhook::auditRejection()`.
            actorRef: null,
            actorRole: 'provider',
            source: AuditSource::Api,
            correlationId: $event->correlation_id,
            metadata: ['note' => sprintf(
                'settlement conflict; provider=%s; incumbent_provider_event=%s; incumbent_status=%s',
                $event->provider,
                $incumbent->getKey(),
                $incumbent->status,
            )],
        );
    }
}
