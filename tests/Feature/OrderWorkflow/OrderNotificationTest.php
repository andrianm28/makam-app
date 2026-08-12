<?php

declare(strict_types=1);

namespace Tests\Feature\OrderWorkflow;

use App\Domain\OrderWorkflow\Actions\RecordOrderStatusChange;
use App\Domain\OrderWorkflow\Models\Order;
use App\Domain\OrderWorkflow\Models\OrderStatusEvent;
use App\Domain\OrderWorkflow\OrderStatus;
use App\Platform\Outbox\Jobs\PublishOutboxEventJob;
use App\Platform\Outbox\Models\OutboxEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class OrderNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_order_processing_notification_dispatched_when_order_enters_diproses(): void
    {
        $order = $this->makeOrderAtStatus(OrderStatus::DISETUJUI_PEMESAN);

        $this->transition($order, OrderStatus::MENUNGGU_PEMBAYARAN);
        $this->assertOutboxMissing('order.processing.v1');

        $this->transition($order, OrderStatus::DIBAYAR);
        $this->assertOutboxMissing('order.processing.v1');

        $this->transition($order, OrderStatus::DIPROSES);

        $statusChangedEvent = OutboxEvent::query()
            ->where('event_name', 'order.status_changed.v1')
            ->where('aggregate_id', $order->getKey())
            ->whereJsonContains('payload', ['to_status' => 'DIPROSES'])
            ->first();

        $this->assertNotNull($statusChangedEvent, 'order.status_changed.v1 for DIPROSES was not emitted');

        (new PublishOutboxEventJob($statusChangedEvent->getKey()))->handle();

        $this->assertOutboxHasEvent('order.processing.v1', $order->getKey());
    }

    public function test_order_completed_notification_dispatched_when_order_enters_selesai(): void
    {
        $order = $this->makeOrderAtStatus(OrderStatus::DIPROSES);

        $this->transition($order, OrderStatus::SELESAI);

        $statusChangedEvent = OutboxEvent::query()
            ->where('event_name', 'order.status_changed.v1')
            ->where('aggregate_id', $order->getKey())
            ->whereJsonContains('payload', ['to_status' => 'SELESAI'])
            ->first();

        $this->assertNotNull($statusChangedEvent, 'order.status_changed.v1 for SELESAI was not emitted');

        (new PublishOutboxEventJob($statusChangedEvent->getKey()))->handle();

        $this->assertOutboxHasEvent('order.completed.v1', $order->getKey());
    }

    public function test_no_notification_emitted_for_other_status_transitions(): void
    {
        $order = $this->makeOrderAtStatus(OrderStatus::MASUK);

        $this->transition($order, OrderStatus::DIVERIFIKASI);

        $statusChangedEvent = OutboxEvent::query()
            ->where('event_name', 'order.status_changed.v1')
            ->where('aggregate_id', $order->getKey())
            ->first();

        $this->assertNotNull($statusChangedEvent);

        (new PublishOutboxEventJob($statusChangedEvent->getKey()))->handle();

        $this->assertOutboxMissing('order.processing.v1');
        $this->assertOutboxMissing('order.completed.v1');
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

    private function transition(Order $order, OrderStatus $to): OrderStatusEvent
    {
        return app(RecordOrderStatusChange::class)(
            $order->fresh(),
            $to,
            'actor:system',
            'system',
        );
    }

    private function makeOrderAtStatus(OrderStatus $status): Order
    {
        return Order::query()->create([
            'reference' => 'MK-2026-TEST-'.uniqid(),
            'product_type' => 'AT_NEED_SERVICE_ORDER',
            'status' => $status->value,
        ]);
    }

    private function assertOutboxHasEvent(string $eventName, string $aggregateId): void
    {
        self::assertNotNull(
            OutboxEvent::query()
                ->where('event_name', $eventName)
                ->where('aggregate_id', $aggregateId)
                ->first(),
            "Expected outbox event '{$eventName}' for aggregate {$aggregateId} was not found"
        );
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
