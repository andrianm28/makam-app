<?php

declare(strict_types=1);

namespace App\Platform\Notification;

/**
 * Prevents `demo-data:seed` (Task 10) from ever queuing a real notification
 * job FOR AN EVENT RAISED DURING THIS RUN. See `DispatchOrderNotifications`
 * and `DispatchNotificationConsumerOnOutboxEventPublished` — both check
 * `active()` immediately before dispatching `ConsumeOutboxNotificationJob`,
 * the actual queued/async notification job. A plain, static, in-process
 * flag is correct here (not a config value, not a database row) because
 * both call sites run synchronously in the SAME PHP process that raised
 * the outbox event in the first place — the seed command's own CLI
 * process. Nothing else in the system ever shares that process, so this
 * flag itself can never suppress or interfere with a real, concurrent
 * customer notification's LISTENER CHECK.
 *
 * What this class does NOT guarantee on its own: it says nothing about
 * which `outbox_events` ROWS get claimed and marked `dispatched_at` while
 * this flag is active. That is a separate concern, owned entirely by
 * whatever drains the outbox during the run — a real, pre-existing or
 * concurrently-arriving row belonging to a real customer must never be
 * claimed or dispatched-stamped just because it happened to be sitting in
 * the table (or land in it) while this flag was true. `DemoDataSeedCommand`
 * satisfies that separately, by never calling the shared, unscoped
 * `OutboxPublisher::publishBatch()` claim during a seed run — it instead
 * publishes only the specific `outbox_events` ids it can positively
 * correlate to this run's own `demo_batch_id`-tagged aggregates. See that
 * command's own doc block for the full mechanism and its known limits.
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
