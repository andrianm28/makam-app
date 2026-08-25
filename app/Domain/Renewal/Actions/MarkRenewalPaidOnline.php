<?php

declare(strict_types=1);

namespace App\Domain\Renewal\Actions;

use App\Domain\Renewal\Exceptions\RenewalAlreadySettledException;
use App\Domain\Renewal\Exceptions\RenewalPaymentAmountMismatchException;
use App\Domain\Renewal\Models\Renewal;
use App\Domain\Renewal\Models\RenewalQuote;
use App\Domain\Renewal\RenewalAuditActions;
use App\Domain\Renewal\RenewalStatus;
use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditOutcome;
use App\Platform\Audit\AuditSource;
use App\Platform\Audit\AuditSubject;
use App\Platform\Correlation\CorrelationContext;
use App\Platform\Outbox\Outbox;
use App\Platform\Outbox\OutboxClassification;
use Illuminate\Support\Facades\DB;

/**
 * The webhook-triggered "mark paid" path for a renewal opened through the
 * online journey — `App\Platform\Payment\Actions\ApplyPaymentSettlement`'s
 * `settleRenewal()` calls this from inside its claim transaction when a
 * validated `payment.completed` webhook resolves to a `Renewal` by
 * `renewals.reference`.
 *
 * Distinct from `Actions\MarkRenewalPaidExternally`, which settles a renewal
 * with money that changed hands OUTSIDE the platform, admin-triggered, with
 * an evidence trail (`RenewalExternalMarking`) and a mandatory human-authored
 * `reason` (`RENEWAL_EXTERNAL_MARKING` is on `SensitiveActions::ACTIONS`).
 * This action settles a renewal with money the platform's own payment
 * gateway collected — the webhook itself is the evidence, so there is no
 * `RenewalExternalMarking` row to write and no human reason to require
 * (`RenewalAuditActions`'s own doc block explains why `RENEWAL_PAID_ONLINE`
 * stays off that list).
 *
 * ---------------------------------------------------------------------------
 * The paid amount is asserted, never assumed
 * ---------------------------------------------------------------------------
 * Mirrors `Domain\CareSubscription\Actions\MarkCyclePaid` /
 * `Domain\Marketplace\Actions\MarkMarketplaceOrderPaid`: the action takes the
 * settled amount as an explicit integer-minor argument and refuses the
 * transition unless it EXACTLY equals the renewal's latest quote
 * `amount_minor` — the same quote `Actions\GuardRenewalPaymentOpening`'s
 * condition 4 checked at session-opening time. The assert runs FIRST, before
 * the idempotency check, the same ordering `CyclePaymentAmountMismatchException`'s
 * doc block documents, so a mismatched replay is refused loudly even against
 * an already-settled renewal — never silently swallowed by the duplicate-
 * arrival no-op below. This also catches the case where the quote drifted (a
 * re-quote) between session opening and settlement — the settlement is only
 * ever trusted against the CURRENT quote, never the session's own stale
 * snapshot alone.
 *
 * ---------------------------------------------------------------------------
 * Idempotency — a duplicate arrival is swallowed, not thrown
 * ---------------------------------------------------------------------------
 * `App\Platform\Payment\ProcessWebhookEvent`'s (provider, provider_transaction_id)
 * claim already stops the SAME provider transaction from settling twice. This
 * guard is the second, independent layer for the case that claim cannot see:
 * two DIFFERENT provider transactions both resolving to the same
 * `renewals.reference`. This is a REAL, reachable race, not a hypothetical —
 * `Actions\GuardRenewalPaymentOpening` has no check against a second payment
 * session being opened for the same still-unpaid renewal (it checks gate,
 * grave, quote and amount, never "does an open session already exist"), so a
 * double-click on "Bayar Sekarang", a reopened stale tab, or a retry after a
 * UI glitch can legitimately produce two payment sessions for the same
 * renewal, both of which get paid.
 *
 * The correct handling of that race is the SAME one `Domain\OrderWorkflow\
 * Actions\ApplyPaidEffects` ("Duplicate arrival: two distinct rejections, one
 * outcome"), `MarkCyclePaid`, and `MarkMarketplaceOrderPaid` all independently
 * converge on: a second settlement for a target that is already in the exact
 * state this call would have produced (`DIBAYAR`, same amount — verified by
 * the assert above running unconditionally) is swallowed — no second
 * RENEWAL write and no second outbox row — and returns the SAME renewal
 * unchanged. It is NOT silent, though (whole-branch review fix wave, 25 Aug
 * 2026): `ProcessWebhookEvent`'s claim guarantees every arrival here is a
 * DIFFERENT provider transaction than the one that settled the renewal
 * first, so this is a genuine second collection, not a replayed delivery —
 * see `recordDuplicateArrival()` below for the
 * `RenewalAuditActions::RENEWAL_PAID_ONLINE_DUPLICATE_ARRIVAL` row this
 * branch now writes, the same visibility `App\Platform\Payment\
 * PaymentAuditActions::DUPLICATE_ARRIVAL` gives the booking leg.
 * `Actions\MarkRenewalPaidExternally`'s own throwing shape is NOT the right
 * precedent here — it is a single-actor, human-triggered admin action with
 * no automated retry loop behind it, not a webhook-driven duplicate-arrival
 * race.
 *
 * Reaching a status that is neither `MENUNGGU_PEMBAYARAN` nor `DIBAYAR` IS
 * still a genuine anomaly and still throws `RenewalAlreadySettledException`
 * — the no-op above is scoped to exactly the state this call itself
 * produces, the same scoping `MarkCyclePaid`/`MarkMarketplaceOrderPaid` use
 * (`status === Paid` / `payment_state === DIBAYAR`, not "any non-open
 * status"). That third status is `KEDALUWARSA`, and it is NOT a
 * hypothetical: `Actions\ExpireRenewal` is a real, live producer of it,
 * wired to a real Filament admin action
 * (`app/Filament/Admin/Resources/RenewalOrders/Actions/ExpireRenewalAction.php`,
 * reachable from `ViewRenewalOrder`). An operator expiring a renewal while
 * the customer's checkout is still live, followed by the customer
 * completing that payment, is the concrete race this branch fails closed
 * on — real money collected, no renewal record updated, the session left
 * stuck, and (before this fix wave) no operator-facing audit row, only a
 * failed background job. `RenewalAuditActions::RENEWAL_PAID_ONLINE_REFUSED`
 * is intended to give an operator reviewing the audit trail visibility into
 * this happening, rather than only discovering it via a stuck `failed_jobs`
 * entry — but on the ONLY real production path, it currently does not
 * survive to be visible.
 *
 * ---------------------------------------------------------------------------
 * KNOWN GAP (24 Aug 2026 final-review re-check): the anomaly audit row does
 * NOT reliably persist in production, despite this class's own `catch`
 * placement outside its `DB::transaction()`
 * ---------------------------------------------------------------------------
 * This Action's own `DB::transaction()` is NOT the outermost one on the real
 * call path. `ApplyPaymentSettlement::settleRenewal()` calls this Action
 * from inside `ProcessWebhookEvent`'s own `DB::transaction()`
 * (`ProcessWebhookEvent.php`), so this class's `DB::transaction()` opens a
 * SAVEPOINT, not a real `BEGIN`. When this method throws, the `catch` below
 * does run and does insert the audit row — but that INSERT lands inside the
 * still-open OUTER transaction, and the exception then propagates out of
 * `settleRenewal()`/`settle()` uncaught, causing `ProcessWebhookEvent` to
 * roll back its own transaction — which erases this row along with
 * everything else. The row only survives when this method is invoked
 * directly, outside any enclosing transaction (exactly what
 * `MarkRenewalPaidOnlineTest.php`'s unit test does, which is why that test
 * is green without proving the production behavior).
 *
 * This was found during this branch's final-review re-check (24 Aug 2026)
 * and deliberately NOT re-fixed in the same pass, per this repo's SDD
 * process: the final whole-branch review gets exactly one fix wave and one
 * scoped re-review, and a residual finding at that point is adjudicated
 * (ruled on or parked), not looped again. This is a real, Important gap —
 * NOT a financial-correctness issue (no double charge, no state corruption;
 * the mutation still fails closed exactly as intended) — deferred as a
 * follow-up: the fix needs to surface this anomaly at a layer that commits,
 * the way `ProcessWebhookEvent::auditSettlementConflict()` already does for
 * a sibling case (record-and-return-an-outcome rather than
 * record-then-throw), not merely move where the `catch` sits.
 *
 * ---------------------------------------------------------------------------
 * `Audit::record()`, not `Audit::wrap()` — deliberately, for the same reason
 * `MarkCyclePaid`'s doc block gives
 * ---------------------------------------------------------------------------
 * `Audit::wrap()` always writes its audit row after a successful mutation,
 * with no way to skip it for a no-op. Since the duplicate-arrival path above
 * must write NEITHER a second audit row NOR a second outbox row, this action
 * uses a plain `DB::transaction()` and calls `Audit::record()` explicitly,
 * only on the real-write branch — exactly `MarkCyclePaid`'s own structure and
 * stated reason.
 */
final readonly class MarkRenewalPaidOnline
{
    public function __invoke(
        Renewal $renewal,
        int $amountMinor,
        string $providerTransactionRef,
        string $actorRef,
    ): Renewal {
        // The anomaly branch inside the transaction below throws, which rolls
        // the transaction back — including anything written inside it. The
        // audit row for that branch must survive the rollback, so it is
        // written HERE, after `DB::transaction()` has already rolled back and
        // rethrown, never inside the closure itself. The duplicate-arrival
        // swallow branch has no such problem (it returns normally, so the
        // transaction it runs in commits), and keeps its own audit write
        // inside the closure, same as `Audit::record()`'s other real callers.
        try {
            return DB::transaction(function () use ($renewal, $amountMinor, $providerTransactionRef, $actorRef): Renewal {
                /** @var Renewal $current */
                $current = Renewal::query()->lockForUpdate()->findOrFail($renewal->getKey());

                // Runs unconditionally, before the status branch below — a
                // mismatched amount is refused even against an already-settled
                // renewal (see this class's own doc block).
                $this->assertAmountMatchesLatestQuote($current, $amountMinor);

                if ($current->status === RenewalStatus::DIBAYAR) {
                    // Swallowed duplicate arrival — see the class doc block's
                    // "Idempotency" section. The amount assert above already
                    // proved this settlement matches the renewal's quote, so
                    // this really is the same FACT arriving twice, not a
                    // conflicting one — no state change, no second RENEWAL
                    // write, no second outbox row. It still gets an audit
                    // row: `ProcessWebhookEvent`'s claim guarantees this is a
                    // genuinely different provider transaction, i.e. a real
                    // second collection, and that must leave a trace an
                    // operator can find to drive a refund decision.
                    $this->recordDuplicateArrival($current, $actorRef);

                    return $current;
                }

                if ($current->status !== RenewalStatus::MENUNGGU_PEMBAYARAN) {
                    // A genuine anomaly — reachable today via `Actions\
                    // ExpireRenewal` (see this class's own doc block). Not the
                    // duplicate-arrival case above, so this still fails
                    // closed; the audit row is written by the catch below,
                    // AFTER this transaction has rolled back.
                    throw RenewalAlreadySettledException::forRenewal((string) $current->getKey());
                }

                $current->update([
                    'status' => RenewalStatus::DIBAYAR,
                    'settled_at' => now(),
                ]);

                if ($renewal !== $current) {
                    $renewal->setRawAttributes($current->getAttributes(), true);
                }

                // References only (`AGENTS.md` §Observability, AC7): no amount.
                // `paid_source_ref` (the provider transaction id) matches
                // `MarkCyclePaid`'s own `care.cycle_created.v1` payload
                // convention exactly — it is not on
                // `PayloadClassification::DENYLISTED_KEYS`, so it is permitted in
                // an outbox payload even though the SAME value stays out of the
                // audit row below (AC14's audit-specific rule, not a blanket
                // outbox rule).
                Outbox::record(
                    eventName: 'renewal.paid_online.v1',
                    eventVersion: 1,
                    aggregateType: 'renewal',
                    aggregateId: $current->getKey(),
                    data: [
                        'renewal_id' => $current->getKey(),
                        'grave_record_id' => $current->grave_record_id,
                        'paid_source_ref' => $providerTransactionRef,
                    ],
                    classification: OutboxClassification::Internal,
                    idempotencyKey: "renewal_paid_online:{$current->getKey()}",
                );

                Audit::record(
                    action: RenewalAuditActions::RENEWAL_PAID_ONLINE,
                    subject: new AuditSubject('renewal', (string) $current->getKey()),
                    outcome: AuditOutcome::Allowed,
                    actorRef: $actorRef,
                    actorRole: 'provider',
                    // The webhook-triggered source, matching
                    // `settleBooking`/`settleMarketplace`/`settleCareSubscription`'s
                    // own `AuditSource::Api`/actor-role-'provider' shape — NOT
                    // `AuditSource::Panel`, which is `MarkRenewalPaidExternally`'s
                    // admin-initiated source.
                    source: AuditSource::Api,
                    correlationId: app(CorrelationContext::class)->current()?->value,
                );

                return $current;
            });
        } catch (RenewalAlreadySettledException $exception) {
            // Reached only by the genuine-anomaly branch above — the
            // duplicate-arrival branch returns normally and never throws
            // this. `DB::transaction()` has already rolled back by the time
            // this catch runs, so this write commits on its own and is not
            // undone by the rollback it is reporting on.
            $this->recordAnomalyRefused($renewal, $actorRef);

            throw $exception;
        }
    }

    /**
     * The duplicate-arrival branch's audit trail — see the class doc block's
     * "Idempotency" section and `RenewalAuditActions::
     * RENEWAL_PAID_ONLINE_DUPLICATE_ARRIVAL`'s own doc block. Runs INSIDE the
     * caller's transaction (that transaction commits, it never rolls back on
     * this branch), so this row commits atomically with the no-op it
     * describes. `note` is always the SAME fixed literal below, never
     * `$providerTransactionRef` or any other provider payload value (AC14).
     */
    private function recordDuplicateArrival(Renewal $renewal, string $actorRef): void
    {
        Audit::record(
            action: RenewalAuditActions::RENEWAL_PAID_ONLINE_DUPLICATE_ARRIVAL,
            subject: new AuditSubject('renewal', (string) $renewal->getKey()),
            outcome: AuditOutcome::Denied,
            actorRef: $actorRef,
            actorRole: 'provider',
            source: AuditSource::Api,
            correlationId: app(CorrelationContext::class)->current()?->value,
            metadata: ['note' => 'duplicate settlement arrival, no state change'],
        );
    }

    /**
     * The genuine-anomaly branch's audit trail — see the class doc block and
     * `RenewalAuditActions::RENEWAL_PAID_ONLINE_REFUSED`'s own doc block.
     * Deliberately called from `__invoke()`'s `catch`, never from inside the
     * `DB::transaction()` closure: a row written there would be rolled back
     * along with the rest of that transaction the moment it throws, leaving
     * exactly the invisible failure this fix exists to close.
     */
    private function recordAnomalyRefused(Renewal $renewal, string $actorRef): void
    {
        Audit::record(
            action: RenewalAuditActions::RENEWAL_PAID_ONLINE_REFUSED,
            subject: new AuditSubject('renewal', (string) $renewal->getKey()),
            outcome: AuditOutcome::Denied,
            actorRef: $actorRef,
            actorRole: 'provider',
            source: AuditSource::Api,
            correlationId: app(CorrelationContext::class)->current()?->value,
            metadata: ['note' => 'settlement arrived for a renewal that is neither open nor already paid'],
        );
    }

    /**
     * The paid transition's precondition, enforced before any write and
     * before the idempotency check: the amount that arrived must EXACTLY
     * equal the renewal's latest quote. A renewal with no quote at all has
     * nothing to verify against and fails closed.
     */
    private function assertAmountMatchesLatestQuote(Renewal $renewal, int $amountMinor): void
    {
        /** @var RenewalQuote|null $quote */
        $quote = $renewal->quotes()->latest()->first();

        if ($quote === null) {
            throw RenewalPaymentAmountMismatchException::becauseNoQuote((string) $renewal->getKey());
        }

        if ($amountMinor !== (int) $quote->amount_minor) {
            throw RenewalPaymentAmountMismatchException::forRenewal(
                (string) $renewal->getKey(),
                (int) $quote->amount_minor,
                $amountMinor,
            );
        }
    }
}
