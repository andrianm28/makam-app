<?php

declare(strict_types=1);

namespace App\Domain\Renewal\Actions;

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
use App\Platform\Payment\SettlementAnomaly;
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
 * stays off that list). `Actions\MarkRenewalPaidExternally`'s own throwing
 * shape is NOT the right precedent for this class's two anomaly branches
 * below — it is a single-actor, human-triggered admin action with no
 * automated retry loop behind it, not a webhook-driven settlement.
 *
 * ---------------------------------------------------------------------------
 * The paid amount is asserted, never assumed
 * ---------------------------------------------------------------------------
 * Mirrors `Domain\CareSubscription\Actions\MarkCyclePaid` /
 * `Domain\Marketplace\Actions\MarkMarketplaceOrderPaid`: the settled amount
 * must EXACTLY equal the renewal's latest quote `amount_minor` — the same
 * quote `Actions\GuardRenewalPaymentOpening`'s condition 4 checked at
 * session-opening time. The check runs FIRST, before the status branch
 * below, so a mismatched replay is refused even against an already-settled
 * renewal — never silently swallowed by the duplicate-arrival no-op. This
 * also catches the case where the quote drifted (a re-quote) between session
 * opening and settlement — the settlement is only ever trusted against the
 * CURRENT quote, never the session's own stale snapshot alone.
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
 * branch writes, the same visibility `App\Platform\Payment\
 * PaymentAuditActions::DUPLICATE_ARRIVAL` gives the booking leg. This branch
 * runs to completion and COMMITS (it never throws), so its audit row was
 * never at risk the way the two anomaly branches below were before Batch
 * M1b.
 *
 * ---------------------------------------------------------------------------
 * Batch M1b (PAY-03, 7 Sep 2026) — the two anomaly branches now RETURN, they
 * never THROW
 * ---------------------------------------------------------------------------
 * Before this fix, an amount mismatch and a settlement against a renewal
 * that is neither open (`MENUNGGU_PEMBAYARAN`) nor already paid (`DIBAYAR`)
 * — reachable today via `Actions\ExpireRenewal`, a REAL, live producer of
 * `KEDALUWARSA`, wired to a real Filament admin action
 * (`app/Filament/Admin/Resources/RenewalOrders/Actions/ExpireRenewalAction.php`)
 * — both threw an exception. That worked correctly for the MUTATION (nothing
 * was ever written for either anomaly, which is still true), but broke the
 * AUDIT TRAIL on the one call path that matters in production:
 * `ApplyPaymentSettlement::settleRenewal()` calls this Action from inside
 * `ProcessWebhookEvent`'s own `DB::transaction()`, so this class's own
 * `DB::transaction()` opened a SAVEPOINT, not a real `BEGIN`. A `catch`
 * placed outside that savepoint could still INSERT an audit row, but that
 * insert landed inside the still-open OUTER transaction — and the moment the
 * exception kept propagating, that outer transaction rolled back and erased
 * the row along with everything else. The amount-mismatch branch never even
 * had a `catch`, so it had no audit row to lose in the first place — a
 * strictly worse starting point.
 *
 * The fix, per this finding's own direction: "record-and-return-an-outcome
 * rather than record-then-throw" — the exact shape
 * `App\Platform\Payment\ProcessWebhookEvent::auditSettlementConflict()`
 * already established for a sibling case (PAY-02 generalised it into
 * `App\Platform\Payment\SettlementAnomaly`). Both anomaly branches now
 * RETURN a `SettlementAnomaly` instead of throwing. Nothing ever unwinds out
 * of this method any more, so there is nothing left to roll back: the
 * write-nothing precondition each anomaly still enforces (no renewal row
 * change, no outbox row) is preserved, but the ability to record an audit
 * trail no longer depends on which transaction happens to be outermost.
 * `ApplyPaymentSettlement::settleRenewal()` forwards the returned anomaly to
 * `ProcessWebhookEvent`, which writes the audit row and moves the
 * `provider_events` row to `MANUAL_REVIEW` INSIDE the transaction that is
 * actually going to commit — see `SettlementAnomaly`'s own doc block.
 *
 * `Exceptions\RenewalAlreadySettledException` / `RenewalPaymentAmountMismatchException`
 * are NOT deleted by this change — `Actions\MarkRenewalPaidExternally` and
 * `Actions\ExpireRenewal` still throw/reference `RenewalAlreadySettledException`
 * on their own admin-triggered, non-webhook call paths, which this fix does
 * not touch and where the throwing shape is still correct.
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
 * stated reason. The two anomaly branches write NO audit row of their own at
 * all any more (see above) — that responsibility moved to the caller.
 */
final readonly class MarkRenewalPaidOnline
{
    public function __invoke(
        Renewal $renewal,
        int $amountMinor,
        string $providerTransactionRef,
        string $actorRef,
    ): Renewal|SettlementAnomaly {
        return DB::transaction(function () use ($renewal, $amountMinor, $providerTransactionRef, $actorRef): Renewal|SettlementAnomaly {
            /** @var Renewal $current */
            $current = Renewal::query()->lockForUpdate()->findOrFail($renewal->getKey());

            // Runs unconditionally, before the status branch below — a
            // mismatched amount is refused even against an already-settled
            // renewal (see this class's own doc block). Returns instead of
            // throwing (PAY-03): nothing has been written yet, so there is
            // nothing to roll back either way, but returning keeps this
            // method's contract uniform with the other anomaly branch below.
            $mismatch = $this->amountMismatch($current, $amountMinor);

            if ($mismatch instanceof SettlementAnomaly) {
                return $mismatch;
            }

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
                // operator can find to drive a refund decision. This
                // branch RETURNS normally (never throws), so this write
                // commits with everything else in the caller's real
                // transaction — it was never at risk the way the two
                // anomaly branches were before Batch M1b.
                $this->recordDuplicateArrival($current, $actorRef);

                return $current;
            }

            if ($current->status !== RenewalStatus::MENUNGGU_PEMBAYARAN) {
                // A genuine anomaly — reachable today via `Actions\
                // ExpireRenewal` (see this class's own doc block). Batch
                // M1b (PAY-03): returns an anomaly instead of throwing —
                // see the class doc block's "the two anomaly branches now
                // RETURN" section for why.
                return $this->anomalousStatus($current);
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
     * Batch M1b (PAY-03). The genuine-anomaly branch's outcome — see the
     * class doc block and `RenewalAuditActions::RENEWAL_PAID_ONLINE_REFUSED`'s
     * own doc block. Writes NO audit row itself any more: the caller
     * (`ProcessWebhookEvent::auditSettlementAnomaly()`) does, from the
     * transaction that actually commits.
     */
    private function anomalousStatus(Renewal $renewal): SettlementAnomaly
    {
        return new SettlementAnomaly(
            auditAction: RenewalAuditActions::RENEWAL_PAID_ONLINE_REFUSED,
            note: 'settlement arrived for a renewal that is neither open nor already paid',
            rejectionDetail: 'renewal settlement target neither open nor already paid',
            subject: new AuditSubject('renewal', (string) $renewal->getKey()),
        );
    }

    /**
     * The paid transition's precondition, enforced before any write and
     * before the idempotency check: the amount that arrived must EXACTLY
     * equal the renewal's latest quote. A renewal with no quote at all has
     * nothing to verify against and is treated the same way — an anomaly,
     * not a settlement.
     *
     * Batch M1b (PAY-03): returns a `SettlementAnomaly` (never throws) —
     * `RenewalAuditActions::RENEWAL_PAID_ONLINE_AMOUNT_MISMATCH`'s own doc
     * block explains why this branch never had an audit row before this fix.
     * No amount value reaches `note`/`rejectionDetail` (AC14) — the mismatch
     * itself, not its magnitude, is the closed-list fact recorded.
     */
    private function amountMismatch(Renewal $renewal, int $amountMinor): ?SettlementAnomaly
    {
        /** @var RenewalQuote|null $quote */
        $quote = $renewal->quotes()->latest()->first();

        if ($quote === null || $amountMinor !== (int) $quote->amount_minor) {
            return new SettlementAnomaly(
                auditAction: RenewalAuditActions::RENEWAL_PAID_ONLINE_AMOUNT_MISMATCH,
                note: 'settlement amount does not match the renewal quote',
                rejectionDetail: 'renewal settlement amount mismatch',
                subject: new AuditSubject('renewal', (string) $renewal->getKey()),
            );
        }

        return null;
    }
}
