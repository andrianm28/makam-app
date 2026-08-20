<?php

declare(strict_types=1);

namespace App\Domain\CareSubscription\Actions;

use App\Domain\CareSubscription\CareSubscriptionAuditActions;
use App\Domain\CareSubscription\Exceptions\CyclePaymentAmountMismatchException;
use App\Domain\CareSubscription\Models\Subscription;
use App\Domain\CareSubscription\Models\SubscriptionCycle;
use App\Domain\CareSubscription\Models\SubscriptionInvoice;
use App\Domain\CareSubscription\SubscriptionCycleStatus;
use App\Domain\CareSubscription\SubscriptionStatus;
use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditOutcome;
use App\Platform\Audit\AuditSource;
use App\Platform\Audit\AuditSubject;
use App\Platform\Correlation\CorrelationContext;
use App\Platform\Outbox\Outbox;
use App\Platform\Outbox\OutboxClassification;
use Illuminate\Support\Facades\DB;

/**
 * Processes a validated webhook payment for a subscription cycle.
 *
 * Cycle -> PAID. If this is the first paid cycle, subscription -> ACTIVE.
 *
 * AC4: No status change from browser return URL — only webhook-validated.
 *
 * ---------------------------------------------------------------------------
 * The paid amount is asserted, never assumed
 * ---------------------------------------------------------------------------
 * Mirrors `Domain\Marketplace\Actions\MarkMarketplaceOrderPaid`: the action
 * takes the paid amount as an explicit integer-minor argument and refuses the
 * transition unless it EXACTLY equals the cycle's invoice `amount_minor` —
 * the invoice is the authoritative money fact, not the arriving payload. The
 * assert runs FIRST, before the idempotency check, so a mismatched replay
 * against an already-PAID cycle is still refused rather than silently
 * accepted.
 *
 * ---------------------------------------------------------------------------
 * Idempotent, and the row is locked before it is branched on
 * ---------------------------------------------------------------------------
 * The row is re-fetched with `lockForUpdate()` inside this action's own
 * transaction (this action previously relied on `Audit::wrap()`'s
 * transaction alone, with no lock and no idempotency guard — a webhook
 * replay would silently re-advance `current_cycle_number`). A cycle already
 * PAID returns unchanged with no state, outbox or audit write — the same
 * "idempotent-if-already-paid, no audit on a no-op replay" shape
 * `MarkMarketplaceOrderPaid` uses, which is why this action now writes its
 * audit row with a single explicit `Audit::record()` call on the real-write
 * path only, rather than `Audit::wrap()` (which would audit every
 * invocation, including a no-op replay).
 */
final readonly class MarkCyclePaid
{
    public function __invoke(
        SubscriptionCycle $cycle,
        int $amountMinor,
        string $paidSourceRef,
        string $actorReference,
    ): SubscriptionCycle {
        return DB::transaction(function () use ($cycle, $amountMinor, $paidSourceRef, $actorReference): SubscriptionCycle {
            /** @var SubscriptionCycle $cycle */
            $cycle = SubscriptionCycle::query()->lockForUpdate()->findOrFail($cycle->getKey());

            /** @var SubscriptionInvoice|null $invoice */
            $invoice = $cycle->invoice;

            $this->assertAmountMatchesInvoice($cycle, $invoice, $amountMinor);

            if ($cycle->status === SubscriptionCycleStatus::Paid->value) {
                return $cycle;
            }

            $cycle->status = SubscriptionCycleStatus::Paid->value;
            $cycle->save();

            /** @var SubscriptionInvoice $invoice */
            $invoice->status = 'paid';
            $invoice->paid_at = now();
            $invoice->save();

            $subscription = Subscription::query()->find($cycle->subscription_id);

            if ($subscription !== null && $subscription->status === SubscriptionStatus::Draft->value) {
                $subscription->status = SubscriptionStatus::Active->value;
                $subscription->started_at = now();
                $subscription->current_cycle_number = 1;
                $subscription->save();
            } elseif ($subscription !== null && $subscription->status === SubscriptionStatus::Active->value) {
                $subscription->current_cycle_number = $subscription->current_cycle_number + 1;
                $subscription->save();
            }

            Outbox::record(
                eventName: 'care.cycle_created.v1',
                eventVersion: 1,
                aggregateType: 'subscription_cycle',
                aggregateId: $cycle->getKey(),
                data: [
                    'subscription_id' => $cycle->subscription_id,
                    'cycle_id' => (string) $cycle->getKey(),
                    'paid_source_ref' => $paidSourceRef,
                ],
                classification: OutboxClassification::Internal,
                idempotencyKey: "cycle:{$cycle->getKey()}",
            );

            Audit::record(
                action: CareSubscriptionAuditActions::CYCLE_PAID,
                subject: new AuditSubject('subscription_cycle', $cycle->getKey()),
                outcome: AuditOutcome::Allowed,
                actorRef: $actorReference,
                actorRole: 'system',
                source: AuditSource::Api,
                correlationId: app(CorrelationContext::class)->current()?->value,
            );

            return $cycle;
        });
    }

    /**
     * The paid transition's precondition, enforced before any write and
     * before the idempotency check: the amount that arrived must EXACTLY
     * equal what the cycle's invoice states. A cycle with no invoice at all
     * has nothing to verify against and fails closed.
     */
    private function assertAmountMatchesInvoice(SubscriptionCycle $cycle, ?SubscriptionInvoice $invoice, int $amountMinor): void
    {
        if ($invoice === null) {
            throw CyclePaymentAmountMismatchException::becauseNoInvoice((string) $cycle->getKey());
        }

        if ($amountMinor !== (int) $invoice->amount_minor) {
            throw CyclePaymentAmountMismatchException::forCycle(
                (string) $cycle->getKey(),
                (int) $invoice->amount_minor,
                $amountMinor,
            );
        }
    }
}
