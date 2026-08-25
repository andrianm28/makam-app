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
 * the assert above running unconditionally) is swallowed and returns the
 * SAME renewal unchanged, writing no second audit row and no second outbox
 * row. `Actions\MarkRenewalPaidExternally`'s own throwing shape is NOT the
 * right precedent here — it is a single-actor, human-triggered admin action
 * with no automated retry loop behind it, not a webhook-driven duplicate-
 * arrival race. Reaching a status that is neither `MENUNGGU_PEMBAYARAN` nor
 * `DIBAYAR` (only `KEDALUWARSA` today, not yet written by any producer) IS
 * still a genuine anomaly and still throws `RenewalAlreadySettledException` —
 * the no-op is scoped to exactly the state this call itself produces, the
 * same scoping `MarkCyclePaid`/`MarkMarketplaceOrderPaid` use (`status ===
 * Paid` / `payment_state === DIBAYAR`, not "any non-open status").
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
                // proved this settlement matches the renewal's quote, so this
                // really is the same fact arriving twice, not a conflicting
                // one. No second write, no second audit row, no second
                // outbox row.
                return $current;
            }

            if ($current->status !== RenewalStatus::MENUNGGU_PEMBAYARAN) {
                // A genuine anomaly — e.g. a payment arriving for a
                // KEDALUWARSA (expired) renewal. Not the duplicate-arrival
                // case above, so this still fails closed.
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
