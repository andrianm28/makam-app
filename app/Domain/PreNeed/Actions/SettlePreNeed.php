<?php

declare(strict_types=1);

namespace App\Domain\PreNeed\Actions;

use App\Domain\OrderWorkflow\Models\Order;
use App\Domain\OrderWorkflow\OrderStatus;
use App\Domain\PreNeed\Exceptions\IllegalPreNeedCaseTransitionException;
use App\Domain\PreNeed\Models\PreNeedCase;
use App\Domain\PreNeed\PreNeedAuditActions;
use App\Domain\PreNeed\PreNeedCaseStatus;
use App\Domain\PreNeed\PreNeedGate;
use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditOutcome;
use App\Platform\Audit\AuditSource;
use App\Platform\Audit\AuditSubject;
use App\Platform\Correlation\CorrelationContext;
use InvalidArgumentException;

/**
 * The paid Pre-Need flow, step 6: `scheduled -> settled` — the manual
 * fallback's settlement, recorded ONLY after the payment evidence is
 * verified (the same discipline `ApplyPaidEffects` enforces for the order:
 * the admin surface calls this action AFTER verification, and the action
 * itself re-checks the evidence's consequence against the database).
 *
 * ---------------------------------------------------------------------------
 * The verification gate: the order's status, never an inference
 * ---------------------------------------------------------------------------
 * The brief's mirror of `MarkOrderPaid`: "settled only when the payment
 * evidence is verified". The case settles only when its pre-need ORDER is
 * actually `DIBAYAR` — the one status the paid effects writer
 * (`ApplyPaidEffects`) can produce, reached only through
 * `RecordOrderStatusChange`'s graph assertion with a persisted event row
 * behind it. The check reads the ORDER's status under its own row lock,
 * so a concurrently-committed payment cannot be missed and an unverified
 * claim cannot pass. An order that is not `DIBAYAR` is an honest refusal
 * (`IllegalPreNeedCaseTransitionException::orderNotPaid`), never a guess.
 *
 * The verified evidence reference itself (`$paidSourceRef`) is recorded on
 * the case (`settled_paid_source_ref`, the plan-gap-fill column — the
 * analogue of `orders.paid_source_ref`), so "what settled this case" stays
 * answerable without a journal query.
 */
final readonly class SettlePreNeed
{
    public function __invoke(
        PreNeedCase $case,
        string $paidSourceRef,
        int|string $actorReference,
        string $actorRole,
        AuditSource $auditSource = AuditSource::Panel,
    ): PreNeedCase {
        if (trim($paidSourceRef) === '') {
            throw new InvalidArgumentException('A pre-need settlement requires a non-blank paid source reference.');
        }

        PreNeedGate::assertOpen($actorReference, $actorRole, $auditSource);

        return Audit::wrap(
            mutation: fn (): PreNeedCase => $this->apply($case, $paidSourceRef),
            action: PreNeedAuditActions::PRENEED_SETTLED,
            subject: new AuditSubject('pre_need_case', $case->getKey()),
            outcome: AuditOutcome::Allowed,
            actorRef: $actorReference,
            actorRole: $actorRole,
            source: $auditSource,
            correlationId: app(CorrelationContext::class)->current()?->value,
        );
    }

    private function apply(PreNeedCase $case, string $paidSourceRef): PreNeedCase
    {
        $current = PreNeedCase::query()->lockForUpdate()->findOrFail($case->getKey());

        $current->status()->assertAllows(PreNeedCaseStatus::SETTLED);

        // The submit-time order, re-read under ITS row lock: the DIBAYAR
        // status is the persisted consequence of the verified payment, and
        // the lock serializes against a concurrent paid transition.
        $orderId = $current->order()?->getKey();

        $order = $orderId !== null ? Order::query()->lockForUpdate()->find($orderId) : null;

        if (! $order instanceof Order || $order->status() !== OrderStatus::DIBAYAR) {
            throw IllegalPreNeedCaseTransitionException::orderNotPaid((string) $current->getKey());
        }

        $current->forceFill([
            'status' => PreNeedCaseStatus::SETTLED->value,
            'settled_paid_source_ref' => $paidSourceRef,
        ])->save();

        return $current;
    }
}
