<?php

declare(strict_types=1);

namespace App\Domain\OrderWorkflow\Listeners;

use App\Domain\OrderWorkflow\OrderStatus;
use App\Platform\Notification\Jobs\ConsumeOutboxNotificationJob;
use App\Platform\Outbox\Events\OutboxEventPublished;
use App\Platform\Outbox\OutboxQueueName;

/**
 * Bridges the ONE canonical order outbox event — `order.status_changed.v1`
 * (`docs/contracts/event-catalog.md`; the plan's Global Constraint / finding
 * N-12: "Never invent an event name") — to the order-lifecycle matrix rows
 * that have no other producer:
 *
 *   - Order is created at MASUK (SubmitBookingDraft's RecordOrderStatusChange::
 *     initial() call) → "Booking submitted" template
 *   - Order transitions to DIPROSES → "Order processing" template
 *   - Order transitions to SELESAI  → "Order completed" template
 *
 * No `order.processing.v1` and no `order.completed.v1` exist. Both matrix
 * rows keep a NULL `outbox_event_name` (they are deliberately absent from
 * the Wave-1a seeder's six-row `outboxEventName()` map — the ruling-1
 * ambiguity: both rows correspond to `order.status_changed.v1`, and the
 * status-discrimination question that ruling left open is resolved HERE, in
 * this bridge, not by inventing catalogue entries). This listener is the
 * status discriminator: it maps `to_status` to the matrix label and hands
 * the source event to the notification seam with that template explicitly
 * selected.
 *
 * The seam is the existing outbox-fed dispatch:
 * `ConsumeOutboxNotificationJob` + `Actions\DispatchNotification::
 * consumeOutboxEvent()` — the same synchronous, in-process
 * `OutboxEventPublished` path `DispatchNotificationConsumerOnOutboxEventPublished`
 * uses for the six outbox-mapped matrix rows. The generic consumer listener
 * does NOT fire for `order.status_changed.v1` (no template maps to it), so
 * this bridge is the sole notification entry point for order transitions.
 *
 * "Booking submitted"'s notification_templates row (seeded from the matrix)
 * carries outbox_event_name = 'booking.draft_submitted.v2' — a catalogued
 * event name no code in this repository emits (SubmitBookingDraft uses the
 * same order.status_changed.v1 + status-discrimination pattern as DIPROSES/
 * SELESAI, not a dedicated submission event). That column value is
 * therefore dead/unused for this row: the lookup here is entirely by
 * event_name via the explicit $matrixEventName argument (see
 * ConsumeOutboxNotificationJob's own doc block), which never reads
 * outbox_event_name. Left as-is rather than edited in the seed migration —
 * changing already-applied seed data is a separate, higher-risk change this
 * task does not need to make.
 *
 * Idempotency (queue delivery is at-least-once): the source
 * `order.status_changed.v1` row already carries the idempotency key
 * `order_status_event:{id}` (`RecordOrderStatusChange::emitStatusChanged()`).
 * A retried `PublishOutboxEventJob` re-fires this listener for the SAME
 * source event id, which re-dispatches the consumer for that id; the seam
 * dedups on `notification_events.event_id` (the outbox event id, its primary
 * key, `insertOrIgnore`) — so a retry can never double-record or double-
 * notify. No new outbox row is ever written here, so nothing here can
 * collide with the source event's idempotency key.
 *
 * `quote.issued.v1`/`quote.accepted.v1` are emitted by IssueQuote and
 * AcceptQuote respectively and need no bridge here. `payment.received.v1`
 * is emitted by ApplyPaidEffects (Task 7). `payment.opened.v1` is deferred
 * to the payment lane that owns the payment-intent creation act.
 *
 * Registered in NotificationServiceProvider via Event::listen() on
 * OutboxEventPublished — the same synchronous, in-process dispatch that
 * DispatchNotificationConsumerOnOutboxEventPublished uses.
 */
final class DispatchOrderNotifications
{
    public function handle(OutboxEventPublished $event): void
    {
        if (($event->envelope['event_name'] ?? null) !== 'order.status_changed.v1') {
            return;
        }

        $toStatus = $event->envelope['data']['to_status'] ?? null;

        $matrixEventName = match ($toStatus) {
            OrderStatus::MASUK->value => 'Booking submitted',
            OrderStatus::DIPROSES->value => 'Order processing',
            OrderStatus::SELESAI->value => 'Order completed',
            default => null,
        };

        if ($matrixEventName === null) {
            return;
        }

        $eventId = $event->envelope['event_id'] ?? null;

        if (! is_string($eventId)) {
            return;
        }

        ConsumeOutboxNotificationJob::dispatch($eventId, $matrixEventName)
            ->onQueue(OutboxQueueName::Notifications->value);
    }
}
