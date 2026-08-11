<?php

declare(strict_types=1);

namespace App\Platform\Notification\Jobs;

use App\Platform\Notification\Actions\DispatchNotification;
use App\Platform\Notification\DeliveryState;
use App\Platform\Notification\Models\NotificationDelivery;
use App\Platform\Notification\NotificationPriority;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Requeues transient channel failures with a bounded exponential delay. The
 * existing queue topology has no `operations` queue, so the terminal job is
 * explicitly tagged and sent to `default` for operational handling.
 */
final class RetryFailedDeliveryJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public const int MAX_ATTEMPTS = 3;

    public function __construct(
        public readonly int $deliveryId,
        public readonly bool $operationalEscalation = false,
    ) {}

    public static function backoffSeconds(int $attempt): int
    {
        $base = min(300, 2 ** max(0, $attempt - 1));

        return min(300, $base + random_int(0, max(1, intdiv($base, 2))));
    }

    public function handle(DispatchNotification $dispatcher): void
    {
        $delivery = NotificationDelivery::query()->find($this->deliveryId);

        if ($delivery === null || $delivery->state !== DeliveryState::Failed) {
            return;
        }

        if ($this->operationalEscalation || $delivery->attempt_count >= self::MAX_ATTEMPTS) {
            if (! $this->operationalEscalation) {
                self::dispatch($this->deliveryId, operationalEscalation: true)
                    ->onQueue(NotificationPriority::Operational->queue()->value);
            }

            Log::error('Notification delivery requires operational handling.', [
                'delivery_id' => $this->deliveryId,
                'error_code' => 'NOTIFICATION_DELIVERY_ESCALATED',
            ]);

            return;
        }

        if (! $dispatcher->requeueFailedDelivery($this, $delivery)) {
            return;
        }

        SendNotificationChannelJob::dispatch($this->deliveryId)
            ->onQueue(NotificationPriority::External->queue()->value);
    }
}
