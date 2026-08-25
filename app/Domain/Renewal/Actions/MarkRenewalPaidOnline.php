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
 * stays off that list). Both actions transition the SAME `renewals.status`
 * column through the SAME `MENUNGGU_PEMBAYARAN -> DIBAYAR` move, which is why
 * both reuse `RenewalAlreadySettledException` for the identical refusal
 * shape rather than each inventing their own.
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
 * the status guard, the same ordering `CyclePaymentAmountMismatchException`'s
 * doc block documents, so a mismatched replay is refused even against an
 * already-settled renewal rather than being swallowed by the idempotency
 * check. This also catches the case where the quote drifted (a re-quote)
 * between session opening and settlement — the settlement is only ever
 * trusted against the CURRENT quote, never the session's own stale snapshot
 * alone.
 *
 * ---------------------------------------------------------------------------
 * Idempotency — defense in depth, not the webhook claim's job to skip
 * ---------------------------------------------------------------------------
 * `App\Platform\Payment\ProcessWebhookEvent`'s (provider, provider_transaction_id)
 * claim already stops the SAME provider transaction from settling twice. This
 * guard is the second, independent layer for the case that claim cannot see:
 * two DIFFERENT provider transactions (e.g. two payment sessions opened for
 * the same renewal) both resolving to the same `renewals.reference`. Rather
 * than silently no-op like `MarkCyclePaid`/`MarkMarketplaceOrderPaid` do for
 * their own domains, this action REFUSES with `RenewalAlreadySettledException`
 * — the same throwing shape `MarkRenewalPaidExternally` already uses and is
 * already tested for (`AdminRenewalActionsTest::test_mark_paid_refuses_settled_renewal`).
 * A second real settlement attempt on a renewal is a financial-integrity
 * anomaly (a double payment collected online), not a routine retry, so it
 * fails closed: the webhook's claim transaction rolls back, the
 * `provider_events` row stays `VALIDATED` for a human to recover, and no
 * `renewal.paid_online.v1` event is emitted twice.
 */
final readonly class MarkRenewalPaidOnline
{
    public function __invoke(
        Renewal $renewal,
        int $amountMinor,
        string $providerTransactionRef,
        string $actorRef,
    ): Renewal {
        return Audit::wrap(
            mutation: function () use ($renewal, $amountMinor, $providerTransactionRef): Renewal {
                /** @var Renewal $current */
                $current = Renewal::query()->lockForUpdate()->findOrFail($renewal->getKey());

                $this->assertAmountMatchesLatestQuote($current, $amountMinor);

                if ($current->status !== RenewalStatus::MENUNGGU_PEMBAYARAN) {
                    throw RenewalAlreadySettledException::forRenewal((string) $current->getKey());
                }

                $current->update([
                    'status' => RenewalStatus::DIBAYAR,
                    'settled_at' => now(),
                ]);

                if ($renewal !== $current) {
                    $renewal->setRawAttributes($current->getAttributes(), true);
                }

                // References only (`AGENTS.md` §Observability, AC7): no
                // amount. `paid_source_ref` (the provider transaction id)
                // matches `MarkCyclePaid`'s own `care.cycle_created.v1`
                // payload convention exactly — it is not on
                // `PayloadClassification::DENYLISTED_KEYS`, so it is
                // permitted in an outbox payload even though the SAME value
                // stays out of the audit row below (AC14's audit-specific
                // rule, not a blanket outbox rule).
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

                return $current;
            },
            action: RenewalAuditActions::RENEWAL_PAID_ONLINE,
            subject: fn (Renewal $result): AuditSubject => new AuditSubject('renewal', (string) $result->getKey()),
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
