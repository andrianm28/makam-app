<?php

declare(strict_types=1);

namespace App\Domain\OrderWorkflow\Actions;

use App\Domain\OrderWorkflow\Exceptions\IllegalOrderTransitionException;
use App\Domain\OrderWorkflow\Exceptions\OrderAlreadyPaidException;
use App\Domain\OrderWorkflow\Exceptions\PaidAmountDoesNotMatchQuoteException;
use App\Domain\OrderWorkflow\Models\Order;
use App\Domain\OrderWorkflow\Models\OrderStatusEvent;
use App\Domain\OrderWorkflow\OrderStatus;
use App\Domain\OrderWorkflow\PaidTrigger;
use App\Domain\Quotation\Models\Quote;
use App\Platform\Outbox\Outbox;
use App\Platform\Outbox\OutboxClassification;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * AC9 — the ONE writer of `DIBAYAR`, and the one place an order's paid
 * effects are applied. Task 7 of
 * `docs/superpowers/plans/2026-08-12-platform-order-orchestration.md`.
 *
 * ---------------------------------------------------------------------------
 * The webhook trigger is WIRED; the manual-verification trigger stays open
 * ---------------------------------------------------------------------------
 * Of the two call sites the plan names (`task-7-brief.md` Ruling 2), one is
 * now real and one is honestly still open:
 *
 *   1. `App\Platform\Payment\ProcessWebhookEvent` — WIRED by Task 5 of
 *      `docs/superpowers/plans/2026-08-14-online-payment-gateway.md`: a
 *      claimed settling event is handed to
 *      `App\Platform\Payment\Actions\ApplyPaymentSettlement`, which resolves
 *      the `payment_sessions` row and, through the event's invoice
 *      reference, the `Order`, then invokes this Action inside the claim
 *      transaction with a `PaidTrigger` built from the session snapshot.
 *      The session -> order link is no longer invented: it travels the
 *      provider round trip (order reference sent as the provider's
 *      `order_id` at session opening, echoed back in the webhook).
 *   2. `App\Platform\Payment\Http\Controllers\VerifyManualPaymentController`
 *      — STILL OPEN. It approves a `payment_verifications` row; that table
 *      carries no order foreign key, so the controller still has no order
 *      to hand this Action. Wiring it would mean inventing that column.
 *
 * Global Constraint, and this lane's standing ruling on the same class of
 * gap: "A missing decision closes the relevant payment/settlement gate; it
 * does not authorize a guessed implementation." The manual half follows
 * that ruling; the webhook half no longer has to.
 *
 * ---------------------------------------------------------------------------
 * No journal batch is posted, and that is a ruling, not an omission
 * ---------------------------------------------------------------------------
 * The plan called for `Journal::post()` alongside the transition.
 * `Journal::post()` calls `assertEntityScoped()`, which rejects a blank
 * `entity_ref`; `entity_ref` is a badan usaha and no column chain reaches
 * one from an `Order`. An unscoped batch would be invisible to every
 * entity-scoped report while still counting toward unscoped totals — two
 * views that disagree permanently with no error anywhere. Same `FIN-DEC-01`
 * gap already ruled on for this lane's payment guard condition 6. No
 * `Journal` import, no invented `entity_ref`, no placeholder account codes.
 *
 * The plan's "two levels of idempotency" paragraph is moot as a consequence:
 * with no journal batch, `order_status_events_paid_once` — the partial
 * unique index on `order_status_events (order_id) WHERE to_status =
 * 'DIBAYAR'` — is the SOLE exactly-once mechanism, which is fine, because it
 * was always the load-bearing one. It is a DATABASE invariant precisely
 * because the cross-path race (an approved manual verification and a late
 * valid webhook for the same order) is concurrent, and no application check
 * can win a race it does not know it is in.
 *
 * ---------------------------------------------------------------------------
 * Sequence, and why this order and no other
 * ---------------------------------------------------------------------------
 * 1. **Precondition, before any write.** The amount must EXACTLY equal the
 *    total of the order's current quote, and that quote must be accepted and
 *    unexpired (`AGENTS.md` §Domain and financial invariants: "Never create
 *    payment before valid confirmation/reservation, accepted quote, and
 *    authorized opening"). Compared as integer MINOR UNITS plus currency —
 *    never a float, never a decimal string. Expiry is evaluated lazily at
 *    guard time against `CarbonImmutable::now()`, the same clock
 *    `GuardPaymentSession` uses, and deliberately NOT against the trigger's
 *    own `occurredAt`: a caller-supplied timestamp could otherwise revive an
 *    expired quote. Running before the transaction means a mismatch leaves
 *    the order untouched — no event row, no audit row, no outbox row, no
 *    stamp.
 * 2. `DB::transaction()`, so the transition, the stamp and the outbox row
 *    commit together or not at all (`AGENTS.md` §Queue and event
 *    reliability: "Critical domain events are inserted into the
 *    transactional outbox in the same database transaction as state
 *    mutation").
 * 3. The transition, through `RecordOrderStatusChange` — the sole
 *    transition writer. It asserts the graph, writes the
 *    `order_status_events` row, moves the column through
 *    `Order::applyStatus()`, writes the audit row in the same transaction
 *    via `Audit::wrap()`, emits `order.status_changed.v1`, and translates an
 *    `order_status_events_paid_once` violation into
 *    `OrderAlreadyPaidException`. None of that is re-implemented here and no
 *    `QueryException` is caught here.
 * 4. `Order::stampPaidSource()` with the event that step 3 returned — the
 *    second authorized door on the model, which opens only for a persisted
 *    `DIBAYAR` token.
 * 5. `payment.received.v1` on the outbox, INSIDE the transaction and AFTER
 *    the transition. That position is the entire reason a duplicate cannot
 *    double-notify: a duplicate throws at step 3 and so never reaches step 5
 *    at all, and even if it somehow did, the whole transaction rolls back.
 *    Moving this call earlier would make the notification depend on a
 *    rollback rather than on control flow; moving it OUTSIDE the transaction
 *    would let a rolled-back payment still notify the customer.
 *
 * ---------------------------------------------------------------------------
 * Duplicate arrival: two distinct rejections, one outcome
 * ---------------------------------------------------------------------------
 * "A duplicate arrival collides, is swallowed, and returns the SAME order —
 * never a second confirmation." Which mechanism rejects it depends on what
 * the competing writer has already committed, and both are handled OUTSIDE
 * the transaction so the rollback completes first:
 *
 *   - **The competing write is fully committed** (event row AND
 *     `orders.status`). `RecordOrderStatusChange` re-reads the order, finds
 *     it at `DIBAYAR`, and `OrderTransition::assertAllowed(DIBAYAR, DIBAYAR)`
 *     refuses — `IllegalOrderTransitionException`, reached before the insert
 *     is ever attempted, so the unique index is never consulted. This is the
 *     SEQUENTIAL case, and it is the one both real trigger paths produce.
 *   - **Only the event row is visible** — the interleaving the partial
 *     unique index exists for. The graph check passes against a stale
 *     status, the insert hits `order_status_events_paid_once`, and
 *     `RecordOrderStatusChange` translates it into
 *     `OrderAlreadyPaidException`.
 *
 * The `IllegalOrderTransitionException` branch is deliberately NARROW: it is
 * only treated as a duplicate when a persisted `DIBAYAR` event for this
 * order actually exists. A paid trigger for an order that never reached a
 * payable state is a genuine illegal transition and is rethrown untouched —
 * swallowing it would turn "this order was never authorized for payment"
 * into a silent success.
 */
final readonly class ApplyPaidEffects
{
    public function __invoke(Order $order, PaidTrigger $trigger): Order
    {
        $this->assertAmountMatchesAcceptedQuote($order, $trigger);

        try {
            return DB::transaction(fn (): Order => $this->apply($order, $trigger));
        } catch (OrderAlreadyPaidException) {
            return $this->alreadyPaid($order);
        } catch (IllegalOrderTransitionException $exception) {
            if (! $this->hasPaidEvent($order)) {
                throw $exception;
            }

            return $this->alreadyPaid($order);
        }
    }

    private function apply(Order $order, PaidTrigger $trigger): Order
    {
        $event = app(RecordOrderStatusChange::class)(
            $order,
            OrderStatus::DIBAYAR,
            $trigger->actorRef,
            $trigger->actorRole,
        );

        $order->stampPaidSource($event, $trigger->source->value, $trigger->sourceId);

        $this->emitPaymentReceived($order, $trigger);

        return $order;
    }

    /**
     * `docs/contracts/event-catalog.md:19` already catalogues
     * `payment.received.v1`; no catalogue row is added and no event name is
     * invented (Global Constraint / finding N-12). The notification template
     * is already wired to it — the Wave-1a seeder
     * `2026_08_09_100020_seed_notification_templates_from_matrix.php` maps
     * "Payment received" to this event name — so no migration is needed
     * here either.
     *
     * Emitted from this Action rather than from
     * `Listeners\DispatchOrderNotifications`: that listener bridges
     * `order.status_changed.v1` to matrix rows with NO other producer, and
     * its own doc block already names this Action as this event's producer.
     *
     * `idempotencyKey` is derived from the trigger's `businessKey`, which is
     * unique per payment arrival, so one trigger can never produce two
     * customer notifications even if it were somehow applied twice —
     * `outbox_events.idempotency_key` is UNIQUE.
     *
     * `data` is REFERENCES ONLY (`AGENTS.md` §Observability, AC7): order id,
     * which trigger, the trigger's own source id, and the amount as integer
     * minor units plus currency. No order content, no party, no document,
     * nothing restricted. The trigger's `occurredAt` is deliberately not
     * repeated here — `Outbox::record()` stamps the row's own `occurred_at`,
     * and a second, caller-supplied timestamp under a similar name is a
     * reconciliation trap rather than information.
     */
    private function emitPaymentReceived(Order $order, PaidTrigger $trigger): void
    {
        Outbox::record(
            eventName: 'payment.received.v1',
            eventVersion: 1,
            aggregateType: 'order',
            aggregateId: (string) $order->getKey(),
            data: [
                'order_id' => (string) $order->getKey(),
                'trigger_source' => $trigger->source->value,
                'source_id' => $trigger->sourceId,
                'amount_minor' => $trigger->amount->toMinorInt(),
                'currency' => $trigger->currency,
            ],
            classification: OutboxClassification::Internal,
            idempotencyKey: "paid_effects:{$trigger->businessKey}",
        );
    }

    /**
     * Step 1 of the sequence — see the class doc block. Integer minor units
     * and currency, and nothing else: no float arithmetic and no decimal
     * string ever enters this comparison, because `Quote` stores
     * `total_minor` as an integer and `Money` is an integer minor-unit value
     * (the decimal -> minor conversion happened exactly once, at quote
     * issuance).
     */
    private function assertAmountMatchesAcceptedQuote(Order $order, PaidTrigger $trigger): void
    {
        $quote = Quote::currentFor($order);

        if ($quote === null || ! $quote->isAcceptedAndUnexpired(CarbonImmutable::now())) {
            throw PaidAmountDoesNotMatchQuoteException::forMissingAcceptedQuote((string) $order->getKey());
        }

        if (strcasecmp(trim($trigger->currency), trim((string) $quote->currency)) !== 0) {
            throw PaidAmountDoesNotMatchQuoteException::forCurrency(
                (string) $order->getKey(),
                (string) $quote->getKey(),
                (string) $quote->currency,
                $trigger->currency,
            );
        }

        if ($trigger->amount->toMinorInt() !== $quote->totalMinor()->toMinorInt()) {
            throw PaidAmountDoesNotMatchQuoteException::forAmount(
                (string) $order->getKey(),
                (string) $quote->getKey(),
                $quote->totalMinor()->toMinorInt(),
                $trigger->amount->toMinorInt(),
            );
        }
    }

    /**
     * Mirrors the `order_status_events_paid_once` predicate exactly. Read
     * after the transaction has rolled back, so it observes committed state
     * only.
     */
    private function hasPaidEvent(Order $order): bool
    {
        return OrderStatusEvent::query()
            ->where('order_id', $order->getKey())
            ->where('to_status', OrderStatus::DIBAYAR->value)
            ->exists();
    }

    /**
     * The duplicate's return value: the order as the winning trigger left
     * it, re-read from the database because this session's own instance may
     * hold attributes from a transaction that has since rolled back. Never a
     * second stamp, never a second outbox row, never a rethrow.
     */
    private function alreadyPaid(Order $order): Order
    {
        return Order::query()->findOrFail($order->getKey());
    }
}
