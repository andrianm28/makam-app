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
 *
 * ---------------------------------------------------------------------------
 * NOTIF-05, 07 Sep 2026 — the retry window was seconds, not minutes
 * ---------------------------------------------------------------------------
 * Before this fix, `MAX_ATTEMPTS` was 3 and `backoffSeconds()` scaled in
 * whole seconds (`2 ** (attempt - 1)`, i.e. ~1s/2s/4s plus jitter) — a
 * total retry window of roughly 7 seconds. ANY provider blip lasting
 * longer than that (a transient DNS hiccup, a provider's own brief
 * maintenance window) permanently failed every delivery in flight, because
 * nothing retried past a single-digit number of seconds. `min(300, ...)`
 * was already present as an outer cap — clearly anticipating a window
 * measured in minutes — but the base it capped never grew past single
 * digits, so the cap never actually engaged.
 *
 * The fix rescales the SAME formula shape onto minutes: `base = min(300,
 * 30 * 2 ** (attempt - 1))` seconds, i.e. 30s / 60s / 120s / 240s /
 * 300s(capped) / 300s(capped) for attempts 1-6 (plus the existing jitter
 * term, unchanged). `MAX_ATTEMPTS` raised 3 -> 6 so the window actually
 * spans that full curve — summed worst case (no jitter) is 1050 seconds,
 * ~17.5 minutes. See `RetryFailedDeliveryJobBackoffTest` for the
 * regression assertion that the summed window is now measured in minutes,
 * not seconds.
 *
 * Kept as this job's own custom backoff logic rather than moving onto the
 * queue job's `$tries`/`$backoff` mechanism: `notification_deliveries.
 * attempt_count` (the business-level send-attempt counter
 * `DispatchNotification::claimDelivery()` increments) and this job's own
 * dispatch count are deliberately two separate counters today — collapsing
 * onto the queue's built-in retry would conflate them. Rescaling the
 * existing formula in place is the smaller, correctness-preserving change.
 */
final class RetryFailedDeliveryJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public const int MAX_ATTEMPTS = 6;

    /**
     * The un-jittered base delay for the FIRST retry attempt, in seconds —
     * 30 seconds, not 1 second, so the exponential curve below actually
     * lands in minutes rather than needing an unreasonable number of
     * attempts to get there.
     */
    private const int BASE_DELAY_SECONDS = 30;

    private const int MAX_DELAY_SECONDS = 300;

    public function __construct(
        public readonly int $deliveryId,
        public readonly bool $operationalEscalation = false,
    ) {}

    public static function backoffSeconds(int $attempt): int
    {
        $base = min(self::MAX_DELAY_SECONDS, self::BASE_DELAY_SECONDS * 2 ** max(0, $attempt - 1));

        return min(self::MAX_DELAY_SECONDS, $base + random_int(0, max(1, intdiv($base, 2))));
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
