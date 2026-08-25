<?php

declare(strict_types=1);

namespace App\Domain\OrderWorkflow\Actions;

use App\Domain\OrderWorkflow\Models\Order;
use App\Domain\OrderWorkflow\Models\OrderStatusEvent;
use App\Domain\OrderWorkflow\OrderStatus;

final readonly class VerifyOrder
{
    public function __invoke(
        Order $order,
        string $actorRef,
        string $actorRole,
        ?string $reason = null,
        array $metadata = [],
    ): OrderStatusEvent {
        return app(RecordOrderStatusChange::class)(
            $order,
            OrderStatus::DIVERIFIKASI,
            $actorRef,
            $actorRole,
            $reason,
            $metadata,
        );
    }
}
