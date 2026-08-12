<?php

declare(strict_types=1);

namespace App\Domain\OrderWorkflow\Listeners;

use App\Domain\OrderWorkflow\OrderStatus;
use App\Platform\Outbox\Events\OutboxEventPublished;
use App\Platform\Outbox\Outbox;
use App\Platform\Outbox\OutboxClassification;

/**
 * Bridges the generic `order.status_changed.v1` outbox event to the specific
 * order-lifecycle matrix rows that have no other event producer:
 *
 *   - Order transitions to DIPROSES     → emits `order.processing.v1`
 *   - Order transitions to SELESAI      → emits `order.completed.v1`
 *
 * `quote.issued.v1` and `quote.accepted.v1` are emitted by IssueQuote and
 * AcceptQuote respectively and need no bridge here. `payment.received.v1`
 * is emitted by ApplyPaidEffects (Task 7). `payment.opened.v1` is deferred
 * to the payment lane that owns the payment-intent creation act.
 *
 * Registered in OrderWorkflowServiceProvider via Event::listen() on
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

        if ($toStatus === OrderStatus::DIPROSES->value) {
            $this->emitOrderProcessing($event->envelope);
        } elseif ($toStatus === OrderStatus::SELESAI->value) {
            $this->emitOrderCompleted($event->envelope);
        }
    }

    private function emitOrderProcessing(array $envelope): void
    {
        Outbox::record(
            eventName: 'order.processing.v1',
            eventVersion: 1,
            aggregateType: $envelope['aggregate']['type'],
            aggregateId: $envelope['aggregate']['id'],
            data: [
                'order_id' => $envelope['aggregate']['id'],
                'from_status' => $envelope['data']['from_status'] ?? null,
                'to_status' => OrderStatus::DIPROSES->value,
            ],
            classification: OutboxClassification::Internal,
        );
    }

    private function emitOrderCompleted(array $envelope): void
    {
        Outbox::record(
            eventName: 'order.completed.v1',
            eventVersion: 1,
            aggregateType: $envelope['aggregate']['type'],
            aggregateId: $envelope['aggregate']['id'],
            data: [
                'order_id' => $envelope['aggregate']['id'],
                'from_status' => $envelope['data']['from_status'] ?? null,
                'to_status' => OrderStatus::SELESAI->value,
            ],
            classification: OutboxClassification::Internal,
        );
    }
}
