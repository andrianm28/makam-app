<?php

declare(strict_types=1);

namespace App\Platform\Notification\Jobs;

use App\Platform\Notification\Actions\DispatchNotification;
use App\Platform\Notification\Contracts\Channel;
use App\Platform\Notification\DeliveryResult;
use App\Platform\Notification\DeliveryState;
use App\Platform\Notification\Models\NotificationDelivery;
use App\Platform\Notification\Models\NotificationRecipient;
use App\Platform\Notification\Models\NotificationTemplateVersion;
use App\Platform\Notification\NotificationPriority;
use App\Platform\Notification\Recipient;
use App\Platform\Notification\RecipientSet;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The per-channel dispatch job — task-3-brief.md D5. One job per
 * `notification_deliveries` row in `QUEUED` state, dispatched by
 * `Actions\DispatchNotification::consumeOutboxEvent()` onto the
 * `notifications` queue AFTER that method's transaction commits. A separate
 * job per channel/recipient — never a loop over several deliveries in one
 * job — so one channel's failure can never block or be conflated with
 * another's (AC5).
 *
 * `Channel` is resolved from the container; tests may bind a test double
 * directly. The job instance is passed to `DispatchNotification`'s typed
 * claim/outcome methods as the mechanical boundary for provider calls.
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
        $delivery = $dispatcher->claimDeliveryForChannelJob($this);

        if ($delivery === null) {
            // Another worker owns the active claim, the row is no longer
            // queued, or the row was removed. The durable claim is the
            // concurrency boundary, not an in-memory check.
            return;
        }

        $version = NotificationTemplateVersion::query()->find($delivery->template_version_id);

        if ($version === null) {
            $dispatcher->recordChannelOutcome($this, $delivery, new DeliveryResult(
                DeliveryState::Unavailable,
                message: DeliveryResult::TEMPLATE_VERSION_UNAVAILABLE,
            ));

            return;
        }

        try {
            $result = $channel->send($delivery, $version, $this->recipientSet($delivery));
        } catch (Throwable $exception) {
            Log::warning('Notification channel send failed.', [
                'error_code' => DeliveryResult::CHANNEL_SEND_FAILED,
                'exception_class' => $exception::class,
            ]);

            $result = new DeliveryResult(
                DeliveryState::Failed,
                message: DeliveryResult::CHANNEL_SEND_FAILED,
            );
        }

        if (! $dispatcher->recordChannelOutcome($this, $delivery, $result)) {
            return;
        }

        if ($result->state !== DeliveryState::Failed) {
            return;
        }

        if (! $result->retryable) {
            self::dispatchRetry($this->deliveryId, operationalEscalation: true);

            return;
        }

        self::dispatchRetry(
            $this->deliveryId,
            delaySeconds: RetryFailedDeliveryJob::backoffSeconds($delivery->attempt_count),
        );
    }

    private static function dispatchRetry(int $deliveryId, bool $operationalEscalation = false, ?int $delaySeconds = null): void
    {
        $job = RetryFailedDeliveryJob::dispatch($deliveryId, operationalEscalation: $operationalEscalation);

        $job->onQueue(($operationalEscalation ? NotificationPriority::Operational : NotificationPriority::External)->queue()->value);

        if ($delaySeconds !== null) {
            $job->delay(now()->addSeconds($delaySeconds));
        }
    }

    private function recipientSet(NotificationDelivery $delivery): RecipientSet
    {
        $recipient = NotificationRecipient::query()->find($delivery->notification_recipient_id);

        if ($recipient === null) {
            return RecipientSet::empty();
        }

        return new RecipientSet([
            new Recipient(
                actorRef: (string) $recipient->recipient_ref,
                actorRole: (string) $recipient->actor_role,
                scopeEntityType: $recipient->scope_entity_type,
                scopeEntityId: $recipient->scope_entity_id,
            ),
        ]);
    }
}
