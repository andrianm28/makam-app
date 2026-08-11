<?php

declare(strict_types=1);

namespace App\Domain\OrderWorkflow\Actions;

use App\Domain\OrderWorkflow\Models\Order;
use App\Domain\OrderWorkflow\Models\OrderStatusEvent;
use App\Domain\OrderWorkflow\OrderStatus;
use App\Domain\OrderWorkflow\OrderTransition;
use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditOutcome;
use App\Platform\Audit\AuditSource;
use App\Platform\Audit\AuditSubject;
use App\Platform\Outbox\Outbox;
use App\Platform\Outbox\OutboxClassification;
use InvalidArgumentException;

/**
 * The ONE writer of `orders.status` and `order_status_events` — Task 2 of
 * `docs/superpowers/plans/2026-08-12-platform-order-orchestration.md`.
 * Every other module that needs to move an order forward calls this
 * Action; nothing else in this codebase writes either the column or the
 * table.
 *
 * ---------------------------------------------------------------------------
 * Sequencing (`task-2-brief.md` Step 3), and why
 * ---------------------------------------------------------------------------
 * 1. Re-read the order with `lockForUpdate()` INSIDE the same transaction
 *    `Audit::wrap()` opens — not the possibly-stale `$order` the caller
 *    passed in. Two concurrent callers racing the same transition each
 *    block on this row lock; the first to commit wins, and the second's
 *    re-read then sees the already-updated status, so its own
 *    `OrderTransition::assertAllowed()` call is what actually rejects it
 *    (`order_status_events_paid_once`, the partial unique index on
 *    `order_status_events`, is the second, database-level backstop for the
 *    `DIBAYAR` case specifically — see the migration's own doc block).
 * 2. `OrderTransition::assertAllowed()` — throws `IllegalOrderTransitionException`
 *    before anything is written.
 * 3. Blank-reason rejection when `$to->requiresReason()` (true only for
 *    `DITOLAK`). Delegates to `Audit::reasonIsBlank()` — the same
 *    Unicode-aware check the audit layer itself uses — rather than
 *    reimplementing it (`task-2-brief.md` ambiguity 2: "do not write your
 *    own blank/empty-string check"). Deliberately NOT done by adding
 *    `ORDER_STATUS_CHANGED` to `SensitiveActions::ACTIONS`: that list makes
 *    a reason mandatory for every occurrence of the action, but only
 *    `DITOLAK` needs one here — the other twelve transitions must keep
 *    working with no reason at all.
 * 4. Insert the `order_status_events` row, then update `orders.status`.
 * 5. Emit `order.status_changed.v1` via the existing `Outbox` — the only
 *    catalogued order event (`docs/contracts/event-catalog.md:20`); no new
 *    event name is invented.
 *
 * The whole sequence runs inside `Audit::wrap()`, which is what actually
 * provides the transaction — AC4's "mutation and its audit record can
 * never be committed separately." If `assertAllowed()` or the blank-reason
 * check throws, the transaction (containing zero writes so far) rolls
 * back and the exception propagates to the caller untouched.
 */
final readonly class RecordOrderStatusChange
{
    public function __invoke(
        Order $order,
        OrderStatus $to,
        string $actorRef,
        string $actorRole,
        ?string $reason = null,
        array $metadata = [],
    ): OrderStatusEvent {
        return Audit::wrap(
            mutation: function () use ($order, $to, $actorRef, $actorRole, $reason, $metadata): OrderStatusEvent {
                $current = Order::query()->lockForUpdate()->findOrFail($order->getKey());
                $from = $current->status();

                OrderTransition::assertAllowed($from, $to);

                if ($to->requiresReason() && Audit::reasonIsBlank($reason)) {
                    throw new InvalidArgumentException(
                        "Transitioning an order to [{$to->value}] requires a non-blank reason."
                    );
                }

                $event = OrderStatusEvent::query()->create([
                    'order_id' => $current->getKey(),
                    'from_status' => $from->value,
                    'to_status' => $to->value,
                    'actor_ref' => $actorRef,
                    'actor_role' => $actorRole,
                    'reason' => $reason,
                    'metadata' => $metadata,
                    'occurred_at' => now(),
                ]);

                $current->forceFill(['status' => $to->value])->save();

                // `event-catalog.md:20` — the only catalogued order event.
                // References only: order id and the two status values, never
                // order content.
                Outbox::record(
                    eventName: 'order.status_changed.v1',
                    eventVersion: 1,
                    aggregateType: 'order',
                    aggregateId: $current->getKey(),
                    data: [
                        'order_id' => $current->getKey(),
                        'from_status' => $from->value,
                        'to_status' => $to->value,
                    ],
                    classification: OutboxClassification::Internal,
                    idempotencyKey: "order_status_event:{$event->getKey()}",
                );

                return $event;
            },
            action: 'ORDER_STATUS_CHANGED',
            subject: fn (OrderStatusEvent $event): AuditSubject => new AuditSubject('order', $event->order_id),
            outcome: AuditOutcome::Allowed,
            actorRef: $actorRef,
            actorRole: $actorRole,
            source: AuditSource::Api,
            reason: $reason,
        );
    }
}
