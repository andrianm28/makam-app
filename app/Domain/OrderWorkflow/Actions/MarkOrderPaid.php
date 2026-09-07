<?php

declare(strict_types=1);

namespace App\Domain\OrderWorkflow\Actions;

use App\Domain\OrderWorkflow\Exceptions\PaidAmountDoesNotMatchQuoteException;
use App\Domain\OrderWorkflow\Models\Order;
use App\Domain\OrderWorkflow\PaidTrigger;
use App\Domain\OrderWorkflow\PaidTriggerSource;
use App\Domain\Quotation\Models\Quote;
use App\Platform\Audit\Audit;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * The admin-panel "mark paid" money attestation — `TransitionOrderAction`
 * dispatches here for the `DIBAYAR` transition, distinct from the WEBHOOK
 * trigger site (`App\Platform\Payment\Actions\ApplyPaymentSettlement::
 * settleBooking()`), which builds its own `PaidTrigger` directly.
 *
 * ---------------------------------------------------------------------------
 * Batch M1b, PAY-07 (7 Sep 2026) — `$reason` now actually reaches the trail
 * ---------------------------------------------------------------------------
 * Before this fix, `$reason` was accepted here and then silently discarded:
 * it never reached `PaidTrigger` (which had no field for it), so an admin's
 * money attestation reason never reached `order_status_events.reason` or the
 * audit trail `Actions\RecordOrderStatusChange` writes. Fixed by threading it
 * through `PaidTrigger::$reason` (see that class's own doc block) into
 * `Actions\ApplyPaidEffects` -> `Actions\RecordOrderStatusChange`.
 *
 * ---------------------------------------------------------------------------
 * Mandatory reason, enforced HERE — deliberately NOT via `SensitiveActions`
 * ---------------------------------------------------------------------------
 * `orders.status = DIBAYAR` is reached through TWO independent trigger
 * sites that share the SAME `Actions\RecordOrderStatusChange` writer and
 * therefore the SAME audit action name (`$to->value`, i.e. literally
 * `'DIBAYAR'`, whenever that value is on `SensitiveActions::ACTIONS` —
 * `RecordOrderStatusChange::record()`'s own doc block): this admin path, and
 * the machine-driven WEBHOOK path. Adding `'DIBAYAR'` to
 * `SensitiveActions::ACTIONS` would make `Audit::record()`'s mandatory-reason
 * check fire for BOTH — including the webhook path, which legitimately has
 * no human-authored reason and would then fail every real payment
 * settlement. That is a production-breaking regression this fix must not
 * introduce, so `SensitiveActions::ACTIONS` is deliberately left untouched
 * here (flagged prominently for human review, per `AGENTS.md`
 * §Infrastructure-agent execution, as a considered deviation from a literal
 * reading of this finding).
 *
 * The mandatory-reason requirement is enforced narrowly instead, scoped to
 * exactly this Action (never the webhook path): a blank reason throws
 * `InvalidArgumentException`, the same blank-check `Audit::reasonIsBlank()`
 * the platform's audit layer already uses elsewhere (mirroring
 * `RecordOrderStatusChange::record()`'s own `$to->requiresReason()` blank
 * check for `DITOLAK` — a per-call-site precondition, not a shared
 * cross-trigger one). `TransitionOrderAction` renders a required `Textarea`
 * for this transition so an admin cannot even submit the form blank; this
 * check is the server-side backstop for a direct call.
 */
final readonly class MarkOrderPaid
{
    public function __construct(
        private ApplyPaidEffects $applyPaidEffects,
    ) {}

    public function __invoke(
        Order $order,
        string $actorRef,
        string $actorRole,
        ?string $reason = null,
    ): Order {
        if (Audit::reasonIsBlank($reason)) {
            throw new InvalidArgumentException(
                'Marking an order paid requires a non-blank reason.'
            );
        }

        $quote = Quote::currentFor($order);

        if (! $quote instanceof Quote) {
            throw PaidAmountDoesNotMatchQuoteException::forMissingAcceptedQuote(
                (string) $order->getKey()
            );
        }

        return ($this->applyPaidEffects)(
            $order,
            new PaidTrigger(
                source: PaidTriggerSource::ManualVerification,
                sourceId: "manual:{$actorRef}",
                businessKey: "manual_paid:{$order->reference}",
                amount: $quote->totalMinor(),
                currency: $quote->currency,
                occurredAt: CarbonImmutable::now(),
                actorRef: $actorRef,
                actorRole: $actorRole,
                reason: $reason,
            ),
        );
    }
}
