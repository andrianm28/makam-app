<?php

declare(strict_types=1);

namespace Tests\Feature\OrderWorkflow;

use App\Domain\OrderWorkflow\Actions\RecordOrderStatusChange;
use App\Domain\OrderWorkflow\Models\Order;
use App\Domain\OrderWorkflow\Models\OrderStatusEvent;
use App\Domain\OrderWorkflow\OrderStatus;
use App\Platform\Notification\Models\NotificationEvent;
use App\Platform\Outbox\Jobs\PublishOutboxEventJob;
use App\Platform\Outbox\Models\OutboxEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The order-lifecycle notification bridge (`Listeners\DispatchOrderNotifications`).
 *
 * The bridge never invents an outbox event name (finding N-12 / the plan's
 * Global Constraint: only `order.status_changed.v1` exists). It maps the
 * canonical event's `to_status` to the notification-matrix label and hands
 * the SOURCE event to the notification seam
 * (`ConsumeOutboxNotificationJob` + `Actions\DispatchNotification::consumeOutboxEvent()`
 * with an explicit template selection), so the observable side effect is a
 * `notification_events` row keyed on the source outbox event id — never a new
 * `order.*.v1` outbox row. At-least-once redelivery is covered by
 * `test_a_retried_publish_does_not_double_record_the_notification` (the same
 * dedup pattern the platform's own
 * `NotificationDispatchPipelineTest::test_ac8_a_duplicate_outbox_delivery_produces_exactly_one_of_everything`
 * proves: `notification_events.event_id` is the primary key, inserted
 * `insertOrIgnore`).
 */
final class OrderNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_order_processing_notification_dispatched_when_order_enters_diproses(): void
    {
        $order = $this->makeOrderAtStatus(OrderStatus::DISETUJUI_PEMESAN);

        // MENUNGGU_PEMBAYARAN now correctly gets its own "Payment opened"
        // notification (this pass's new coverage) — this test's own scope is
        // DIPROSES specifically, so it asserts that one directly rather than
        // re-asserting "Payment opened" as a side detail; DIBAYAR still gets
        // nothing, same as before.
        $this->transition($order, OrderStatus::MENUNGGU_PEMBAYARAN);
        $this->assertNoNotificationFor($this->transition($order, OrderStatus::DIBAYAR));

        $this->assertNotificationFor($this->transition($order, OrderStatus::DIPROSES), 'Order processing');

        $this->assertOutboxMissing('order.processing.v1');
        $this->assertOutboxMissing('order.completed.v1');
    }

    public function test_booking_submitted_notification_dispatched_when_order_is_created_at_masuk(): void
    {
        $order = $this->makeOrderAtStatus(OrderStatus::MASUK);

        $event = app(RecordOrderStatusChange::class)->initial(
            $order,
            'actor:test',
            'system',
        );

        $this->assertNotificationFor($event, 'Booking submitted');

        $this->assertOutboxMissing('order.processing.v1');
        $this->assertOutboxMissing('order.completed.v1');
    }

    public function test_order_completed_notification_dispatched_when_order_enters_selesai(): void
    {
        $order = $this->makeOrderAtStatus(OrderStatus::DIPROSES);

        $this->assertNotificationFor($this->transition($order, OrderStatus::SELESAI), 'Order completed');

        $this->assertOutboxMissing('order.processing.v1');
        $this->assertOutboxMissing('order.completed.v1');
    }

    public function test_no_notification_emitted_for_other_status_transitions(): void
    {
        $order = $this->makeOrderAtStatus(OrderStatus::MASUK);

        $this->assertNoNotificationFor($this->transition($order, OrderStatus::DIVERIFIKASI));

        $this->assertOutboxMissing('order.processing.v1');
        $this->assertOutboxMissing('order.completed.v1');
    }

    public function test_availability_requested_notification_dispatched_when_order_enters_menunggu_ketersediaan(): void
    {
        $order = $this->makeOrderAtStatus(OrderStatus::DIVERIFIKASI);

        $this->assertNotificationFor($this->transition($order, OrderStatus::MENUNGGU_KETERSEDIAAN), 'Availability requested');
    }

    public function test_availability_confirmed_notification_dispatched_when_order_enters_penawaran_terkirim(): void
    {
        $order = $this->makeOrderAtStatus(OrderStatus::MENUNGGU_KETERSEDIAAN);

        $this->assertNotificationFor($this->transition($order, OrderStatus::PENAWARAN_TERKIRIM), 'Availability confirmed/rejected');
    }

    public function test_availability_rejected_notification_dispatched_when_order_is_rejected_from_menunggu_ketersediaan(): void
    {
        $order = $this->makeOrderAtStatus(OrderStatus::MENUNGGU_KETERSEDIAAN);

        $this->assertNotificationFor($this->transition($order, OrderStatus::DITOLAK, 'Tidak tersedia pada tanggal diminta'), 'Availability confirmed/rejected');
    }

    /**
     * The load-bearing negative case for the `from_status` discrimination:
     * `DITOLAK` is reachable from `MASUK`, `DIVERIFIKASI`, AND
     * `MENUNGGU_KETERSEDIAAN` (`OrderTransition`'s own state table), but only
     * the third one is an availability rejection. A `to_status`-only match
     * would incorrectly fire "Availability confirmed/rejected" for THIS
     * transition too — it must not.
     */
    public function test_no_availability_notification_when_order_is_rejected_from_masuk(): void
    {
        $order = $this->makeOrderAtStatus(OrderStatus::MASUK);

        $this->assertNoNotificationFor($this->transition($order, OrderStatus::DITOLAK, 'Data pemesan tidak lengkap'));
    }

    /** Same discrimination, the other non-availability `DITOLAK` source. */
    public function test_no_availability_notification_when_order_is_rejected_from_diverifikasi(): void
    {
        $order = $this->makeOrderAtStatus(OrderStatus::DIVERIFIKASI);

        $this->assertNoNotificationFor($this->transition($order, OrderStatus::DITOLAK, 'Data pemesan tidak lengkap'));
    }

    public function test_payment_opened_notification_dispatched_when_order_enters_menunggu_pembayaran(): void
    {
        $order = $this->makeOrderAtStatus(OrderStatus::DISETUJUI_PEMESAN);

        $this->assertNotificationFor($this->transition($order, OrderStatus::MENUNGGU_PEMBAYARAN), 'Payment opened');
    }

    public function test_channel_failure_does_not_change_business_state(): void
    {
        $order = $this->makeOrderAtStatus(OrderStatus::DIPROSES);

        $this->transition($order, OrderStatus::SELESAI);

        self::assertSame('SELESAI', $order->fresh()->status);
        self::assertNotNull(
            OrderStatusEvent::query()
                ->where('order_id', $order->getKey())
                ->where('to_status', 'SELESAI')
                ->first()
        );
    }

    /**
     * Important-2 regression: a retried `PublishOutboxEventJob` re-fires the
     * bridge for the SAME source `order.status_changed.v1` event, which
     * re-dispatches the seam for the SAME outbox event id. The seam dedups
     * on `notification_events.event_id` (insertOrIgnore, primary key), so
     * the customer is never notified twice. Mirrors the platform's own
     * duplicate-outbox-delivery pattern
     * (`NotificationDispatchPipelineTest::test_ac8_a_duplicate_outbox_delivery_produces_exactly_one_of_everything`).
     */
    public function test_a_retried_publish_does_not_double_record_the_notification(): void
    {
        $order = $this->makeOrderAtStatus(OrderStatus::DISETUJUI_PEMESAN);

        $this->transition($order, OrderStatus::MENUNGGU_PEMBAYARAN);
        $this->transition($order, OrderStatus::DIBAYAR);
        $event = $this->transition($order, OrderStatus::DIPROSES);
        $sourceOutboxEvent = $this->statusChangedFor($order, OrderStatus::DIPROSES);
        self::assertNotNull($sourceOutboxEvent, 'order.status_changed.v1 for DIPROSES was not emitted');
        self::assertSame("order_status_event:{$event->getKey()}", $sourceOutboxEvent->idempotency_key);

        (new PublishOutboxEventJob($sourceOutboxEvent->getKey()))->handle();
        (new PublishOutboxEventJob($sourceOutboxEvent->getKey()))->handle();

        self::assertSame(
            1,
            NotificationEvent::query()->where('event_id', $sourceOutboxEvent->getKey())->count(),
            'A retried publish must not double-record the notification'
        );

        self::assertOutboxMissing('order.processing.v1');
        self::assertOutboxMissing('order.completed.v1');
    }

    private function transition(Order $order, OrderStatus $to, ?string $reason = null): OrderStatusEvent
    {
        return app(RecordOrderStatusChange::class)(
            $order->fresh(),
            $to,
            'actor:system',
            'system',
            $reason,
        );
    }

    private function statusChangedFor(Order $order, OrderStatus $to): ?OutboxEvent
    {
        return OutboxEvent::query()
            ->where('event_name', 'order.status_changed.v1')
            ->where('aggregate_id', $order->getKey())
            ->whereJsonContains('payload', ['to_status' => $to->value])
            ->first();
    }

    private function assertNoNotificationFor(OrderStatusEvent $event): void
    {
        $outboxEvent = OutboxEvent::query()
            ->where('idempotency_key', "order_status_event:{$event->getKey()}")
            ->first();
        self::assertNotNull($outboxEvent, 'order.status_changed.v1 was not emitted for this transition');

        (new PublishOutboxEventJob($outboxEvent->getKey()))->handle();

        self::assertNull(
            NotificationEvent::query()->where('event_id', $outboxEvent->getKey())->first(),
            "Transition to {$event->to_status} should not record any notification"
        );
    }

    private function assertNotificationFor(OrderStatusEvent $event, string $matrixEventName): void
    {
        $outboxEvent = OutboxEvent::query()
            ->where('idempotency_key', "order_status_event:{$event->getKey()}")
            ->first();
        self::assertNotNull($outboxEvent, 'order.status_changed.v1 was not emitted for this transition');

        (new PublishOutboxEventJob($outboxEvent->getKey()))->handle();

        $notificationEvent = NotificationEvent::query()
            ->where('event_id', $outboxEvent->getKey())
            ->first();
        self::assertNotNull($notificationEvent, "Transition to {$event->to_status} should record a notification");
        self::assertSame($matrixEventName, $notificationEvent->matrix_event_name);
    }

    private function makeOrderAtStatus(OrderStatus $status): Order
    {
        return Order::query()->create([
            'reference' => 'MK-2026-TEST-'.uniqid(),
            'product_type' => 'AT_NEED_SERVICE_ORDER',
            'status' => $status->value,
        ]);
    }

    private function assertOutboxMissing(string $eventName): void
    {
        self::assertNull(
            OutboxEvent::query()
                ->where('event_name', $eventName)
                ->first(),
            "Outbox event '{$eventName}' was found but should not exist"
        );
    }
}
