<?php

declare(strict_types=1);

namespace App\Domain\OrderWorkflow\Listeners;

use App\Domain\OrderWorkflow\OrderStatus;
use App\Platform\Notification\DemoDataSuppression;
use App\Platform\Notification\Jobs\ConsumeOutboxNotificationJob;
use App\Platform\Outbox\Events\OutboxEventPublished;
use App\Platform\Outbox\OutboxQueueName;
use Illuminate\Support\Facades\Log;

/**
 * Bridges the ONE canonical order outbox event — `order.status_changed.v1`
 * (`docs/contracts/event-catalog.md`; the plan's Global Constraint / finding
 * N-12: "Never invent an event name") — to the order-lifecycle matrix rows
 * that have no other producer:
 *
 *   - Order is created at MASUK (SubmitBookingDraft's RecordOrderStatusChange::
 *     initial() call) → "Booking submitted" template
 *   - Order transitions to MENUNGGU_KETERSEDIAAN (RequestAvailability) →
 *     "Availability requested" template
 *   - Order transitions to PENAWARAN_TERKIRIM (IssueOrderQuote) →
 *     "Availability confirmed/rejected" template — unambiguous by `to_status`
 *     alone: `OrderTransition`'s own state table names `MENUNGGU_KETERSEDIAAN`
 *     as the ONLY status that can transition here.
 *   - Order transitions to DITOLAK (RejectOrder), but ONLY when the
 *     transition's own `from_status` was MENUNGGU_KETERSEDIAAN → same
 *     "Availability confirmed/rejected" template — `to_status` alone is
 *     NOT enough to discriminate here, unlike the other arms: `DITOLAK` is
 *     reachable from THREE statuses (`MASUK`, `DIVERIFIKASI`,
 *     `MENUNGGU_KETERSEDIAAN`, per `OrderTransition`'s own state table), and
 *     only the third one is an availability rejection — the other two are
 *     earlier-stage order rejections with no corresponding matrix row, and
 *     must NOT fire this template.
 *   - Order transitions to MENUNGGU_PEMBAYARAN (GrantOrderPaymentOpening) →
 *     "Payment opened" template — unambiguous by `to_status` alone: only
 *     `DISETUJUI_PEMESAN` transitions here.
 *   - Order transitions to DIPROSES → "Order processing" template
 *   - Order transitions to SELESAI  → "Order completed" template
 *
 * No dedicated outbox event name exists for any of these seven rows — all
 * seven notification_templates rows (seeded verbatim from
 * `docs/contracts/notification-matrix.md`'s row labels) keep either a NULL
 * or a dead/unused `outbox_event_name` column (see the "Booking submitted"
 * paragraph below for why a non-null value there is still correctly unused).
 * This listener is the status discriminator for all of them: it maps
 * `to_status` (and, for the one genuinely ambiguous case, `from_status` too)
 * to the matrix label and hands the source event to the notification seam
 * with that template explicitly selected.
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
 * outbox_event_name. "Availability requested"/"Availability
 * confirmed/rejected" carry the same shape — `availability.requested.v1`/
 * `availability.confirmed.v2` are catalogued names (`docs/contracts/
 * event-catalog.md`) that no code emits, for the identical reason: this
 * bridge's own event_name lookup never reads them. "Payment opened" has no
 * catalogued name at all. All are left as-is rather than edited in the seed
 * migration — changing already-applied seed data is a separate, higher-risk
 * change this task does not need to make.
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
 * is emitted by ApplyPaidEffects (Task 7).
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
        $fromStatus = $event->envelope['data']['from_status'] ?? null;

        $matrixEventName = match (true) {
            $toStatus === OrderStatus::MASUK->value => 'Booking submitted',
            $toStatus === OrderStatus::MENUNGGU_KETERSEDIAAN->value => 'Availability requested',
            $toStatus === OrderStatus::PENAWARAN_TERKIRIM->value => 'Availability confirmed/rejected',
            $toStatus === OrderStatus::DITOLAK->value
                && $fromStatus === OrderStatus::MENUNGGU_KETERSEDIAAN->value => 'Availability confirmed/rejected',
            $toStatus === OrderStatus::MENUNGGU_PEMBAYARAN->value => 'Payment opened',
            $toStatus === OrderStatus::DIPROSES->value => 'Order processing',
            $toStatus === OrderStatus::SELESAI->value => 'Order completed',
            default => null,
        };

        if ($matrixEventName === null) {
            return;
        }

        $eventId = $event->envelope['event_id'] ?? null;

        if (! is_string($eventId)) {
            return;
        }

        if (DemoDataSuppression::active()) {
            Log::info('notification.suppressed_for_demo_seeding', [
                'outbox_event_id' => $eventId,
                'matrix_event_name' => $matrixEventName,
            ]);

            return;
        }

        ConsumeOutboxNotificationJob::dispatch($eventId, $matrixEventName)
            ->onQueue(OutboxQueueName::Notifications->value);
    }
}
