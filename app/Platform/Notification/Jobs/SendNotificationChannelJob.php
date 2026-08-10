<?php

declare(strict_types=1);

namespace App\Platform\Notification\Jobs;

use App\Platform\Notification\Actions\DispatchNotification;
use App\Platform\Notification\Contracts\Channel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * The per-channel dispatch job — task-3-brief.md D5. One job per
 * `notification_deliveries` row in `QUEUED` state, dispatched by
 * `Actions\DispatchNotification::consumeOutboxEvent()` onto the
 * `notifications` queue AFTER that method's transaction commits. A separate
 * job per channel/recipient — never a loop over several deliveries in one
 * job — so one channel's failure can never block or be conflated with
 * another's (AC5).
 *
 * `Channel` is resolved from the container with no default binding from
 * this lane — Task 4 adds the real implementation and its binding (D5).
 * Tests bind a test double directly.
 */
final class SendNotificationChannelJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly int $deliveryId,
    ) {}

    public function handle(DispatchNotification $dispatcher, Channel $channel): void
    {
        $dispatcher->sendViaChannel($this->deliveryId, $channel);
    }
}
