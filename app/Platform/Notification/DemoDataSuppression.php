<?php

declare(strict_types=1);

namespace App\Platform\Notification;

/**
 * Prevents `demo-data:seed` (Task 10) from ever queuing a real notification
 * job. See `DispatchOrderNotifications` and
 * `DispatchNotificationConsumerOnOutboxEventPublished` — both check
 * `active()` immediately before dispatching `ConsumeOutboxNotificationJob`,
 * the actual queued/async notification job. A plain, static, in-process
 * flag is correct here (not a config value, not a database row) because
 * both call sites run synchronously in the SAME PHP process that raised
 * the outbox event in the first place — the seed command's own CLI
 * process. Nothing else in the system ever shares that process, so this
 * can never suppress or interfere with a real, concurrent customer
 * notification.
 */
final class DemoDataSuppression
{
    private static bool $active = false;

    public static function active(): bool
    {
        return self::$active;
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function run(callable $callback): mixed
    {
        self::$active = true;

        try {
            return $callback();
        } finally {
            self::$active = false;
        }
    }
}
